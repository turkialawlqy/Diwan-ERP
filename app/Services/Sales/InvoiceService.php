<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\ExchangeRates;
use App\Services\Documents\DocumentNumberService;
use App\Services\Inventory\DocumentStock;
use App\Services\Saas\WebhookDispatcher;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Services\Zatca\EInvoiceManager;
use App\Services\Zatca\VatClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues sales tax invoices (standard and simplified) from any channel:
 * the invoice screen, quotations, sales orders, delivery notes.
 *
 * In one transaction: sequential number, line totals, credit limit check,
 * customer balance, stock issue at cost (unless already delivered), journal
 * entry and the ZATCA QR / e-invoice stamp.
 */
class InvoiceService
{
    public function __construct(
        protected TenantManager $tenantManager,
        protected DocumentNumberService $numbers,
        protected DocumentStock $stock,
        protected DocumentPoster $poster,
    ) {}

    /**
     * @param  array  $data  customer_id, invoice_date, payment_method, payment_status?, paid_amount?, branch_id?, warehouse_id?,
     *                       bank_account_id?, due_date?, supply_date?, reference_number?, notes?, terms?, salesperson_id?,
     *                       sales_order_id?, delivery_note_id?, invoice_type?, number_type?
     * @param  array  $lines  product_id?, item_name, description?, quantity, unit_price, discount?, tax_rate?, unit_id?, batch_id?, serials?
     * @param  array  $options  issue_stock (default true), cost (known cost when stock already issued), check_credit (default true),
     *                          post (default true; false when the caller posts its own entry, e.g. project billings),
     *                          revenue_role (default sales_revenue; e.g. rental_revenue for equipment rent)
     */
    public function create(array $data, array $lines, array $options = []): Invoice
    {
        $company = $this->tenantManager->getCompany();

        return DB::transaction(function () use ($data, $lines, $options, $company) {
            // The branch's country decides the tax (rates, title, e-invoicing) and the default currency.
            $branchId = $data['branch_id'] ?? auth()->user()?->defaultBranchId() ?? Branch::orderByDesc('is_main')->orderBy('id')->value('id');
            $profile = TaxProfile::forBranch($branchId ? (int) $branchId : null, $company);
            [$items, $subtotal, $taxAmount] = $this->computeLines($lines, $profile, $data['invoice_date'] ?? null);
            $total = round($subtotal + $taxAmount, 2);

            [$paidAmount, $remaining, $status] = $this->payment($data, $total);
            // Amounts are in the invoice currency; balances and the ledger use base.
            $currencyCode = array_key_exists('currency_code', $data) && $data['currency_code'] ? $data['currency_code'] : $profile->currency();
            [$currency, $rate] = app(ExchangeRates::class)->resolve($currencyCode, $data['exchange_rate'] ?? null, $data['invoice_date']);

            $customer = Customer::lockForUpdate()->findOrFail($data['customer_id']);
            if (($options['check_credit'] ?? true) && $remaining > 0) {
                $this->checkCreditLimit($customer, ExchangeRates::toBase($remaining, $rate));
            }

            $date = Carbon::parse($data['invoice_date']);
            $requestedSimplified = ($data['invoice_type'] ?? null) === 'simplified';
            $isSimplified = $requestedSimplified || $this->buyerNeedsSimplified($customer, $profile);

            $invoice = Invoice::create([
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'salesperson_id' => $data['salesperson_id'] ?? $customer->salesperson_id ?? auth()->id(),
                'invoice_number' => $this->numbers->next($data['number_type'] ?? ($requestedSimplified ? 'pos_invoice' : 'sales_invoice'), $date),
                'invoice_type' => $isSimplified ? 'simplified' : 'standard',
                'reference_number' => $data['reference_number'] ?? null,
                'invoice_date' => $date->toDateString(),
                'due_date' => $data['due_date'] ?? ($customer->payment_terms_days ? $date->copy()->addDays($customer->payment_terms_days)->toDateString() : null),
                'supply_date' => $data['supply_date'] ?? $date->toDateString(),
                'subtotal' => $subtotal,
                'discount_type' => 'fixed',
                'discount_value' => 0,
                'discount_amount' => round(array_sum(array_column($items, 'discount')), 2),
                'tax_rate' => $profile->standardRate($date),
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remaining,
                'payment_status' => $status,
                'payment_method' => $data['payment_method'] ?? 'credit',
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'delivery_note_id' => $data['delivery_note_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'zatca_uuid' => (string) Str::uuid(),
                'zatca_status' => 'not_submitted',
                'currency_code' => $currency,
                'exchange_rate' => $rate,
            ]);

            foreach ($items as $item) {
                $invoice->items()->create($item);
            }

            if ($remaining > 0) {
                $customer->increment('current_balance', ExchangeRates::toBase($remaining, $rate));
            }

            // Stock leaves the warehouse at actual cost, unless it already left
            // with a delivery note (then the cost was posted on delivery).
            $cost = $options['cost'] ?? null;
            if ($options['issue_stock'] ?? true) {
                $warehouseId = $this->stock->warehouse($data['warehouse_id'] ?? null);
                $invoice->forceFill(['warehouse_id' => $warehouseId])->saveQuietly();
                $cost = $this->stock->issue($lines, $warehouseId, 'sale', $invoice, $date, $invoice->invoice_number);
            }

            if ($options['post'] ?? true) {
                $this->poster->salesInvoice($invoice, $invoice->warehouse_id, (float) ($cost ?? 0), $options['revenue_role'] ?? 'sales_revenue');
            }

            $this->stamp($invoice, $company);

            app(WebhookDispatcher::class)->dispatch('invoice.created', [
                'id' => $invoice->id, 'number' => $invoice->invoice_number, 'type' => $invoice->invoice_type, 'customer_id' => $invoice->customer_id,
                'date' => $invoice->invoice_date, 'subtotal' => (float) $invoice->subtotal, 'vat' => (float) $invoice->tax_amount, 'total' => (float) $invoice->total,
                'remaining' => (float) $invoice->remaining_amount, 'zatca_uuid' => $invoice->zatca_uuid,
            ]);

            return $invoice;
        });
    }

    /**
     * Normalise lines and compute line / document totals (rounded per line).
     */
    /**
     * ZATCA: a standard (B2B) tax invoice is cleared with the buyer's VAT
     * number and national address. A local buyer with no VAT number receives
     * a simplified invoice; one with a VAT number must have a full address,
     * otherwise clearance would reject the invoice after it was posted.
     */
    protected function buyerNeedsSimplified(Customer $customer, ?TaxProfile $profile = null): bool
    {
        $profile ??= TaxProfile::current();
        if (! $profile->simplifiedInvoices() || ! $profile->chargesTax()) {
            return $customer->code === 'WALK-IN' && $profile->simplifiedInvoices();
        }
        // A buyer in the branch's own country is local (the national-address rule is Saudi).
        $local = TaxProfile::countryCodeFromName($customer->country, $profile->countryCode) === $profile->countryCode;
        if (! $local || $customer->code === 'WALK-IN') {
            return $customer->code === 'WALK-IN';
        }
        if (! $customer->tax_number) {
            return true;
        }
        if (! $profile->requiresNationalAddress()) {
            return false;
        }

        $missing = collect([
            'رقم المبنى' => $customer->building_number,
            'الشارع' => $customer->street_name ?: $customer->address,
            'الحي' => $customer->district,
            'المدينة' => $customer->city,
            'الرمز البريدي' => $customer->postal_code,
        ])->filter(fn ($v) => blank($v))->keys();
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['customer_id' => "أكمل العنوان الوطني للعميل «{$customer->name}» قبل إصدار فاتورة ضريبية (العملاء ← تعديل العميل ← العنوان): ".$missing->implode('، ').'.']);
        }

        return false;
    }

    public function computeLines(array $lines, ?TaxProfile $profile = null, $date = null): array
    {
        $profile ??= TaxProfile::current();
        $items = [];
        $subtotal = 0.0;
        $taxAmount = 0.0;

        foreach ($lines as $line) {
            $qty = (float) $line['quantity'];
            $price = (float) $line['unit_price'];
            $discount = round((float) ($line['discount'] ?? 0), 2);
            $taxRate = isset($line['tax_rate']) && $line['tax_rate'] !== '' ? (float) $line['tax_rate'] : $profile->standardRate($date);

            if ($qty <= 0 || $price < 0 || $discount < 0) {
                throw ValidationException::withMessages(['items' => 'كميات وأسعار الأصناف يجب أن تكون موجبة.']);
            }

            $lineSubtotal = round($qty * $price - $discount, 2);
            if ($lineSubtotal < 0) {
                throw ValidationException::withMessages(['items' => 'خصم السطر لا يمكن أن يتجاوز قيمته.']);
            }

            // VAT category / exemption reason (product, line or company default);
            // zero-rated, exempt and out-of-scope lines carry no VAT.
            [$vatCategory, $exemptionCode] = app(VatClassifier::class)->classify(
                ! empty($line['product_id']) ? Product::find($line['product_id']) : null,
                $taxRate, $line['vat_category'] ?? null, $line['vat_exemption_code'] ?? null, $profile,
            );
            // Standard-rated lines carry a rate the branch's country allows (KSA: always 15); others carry none.
            $taxRate = $vatCategory === 'S' ? $profile->rateFor($taxRate, $date) : 0.0;

            $lineTax = round($lineSubtotal * $taxRate / 100, 2);

            $items[] = [
                'product_id' => $line['product_id'] ?? null,
                'item_name' => $line['item_name'],
                'description' => $line['description'] ?? null,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => $discount,
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTax,
                'vat_category' => $vatCategory,
                'vat_exemption_code' => $exemptionCode,
                'subtotal' => $lineSubtotal,
                'total' => round($lineSubtotal + $lineTax, 2),
            ];

            $subtotal += $lineSubtotal;
            $taxAmount += $lineTax;
        }

        if (! $items) {
            throw ValidationException::withMessages(['items' => 'الفاتورة يجب أن تحتوي على صنف واحد على الأقل.']);
        }

        return [$items, round($subtotal, 2), round($taxAmount, 2)];
    }

    protected function payment(array $data, float $total): array
    {
        $requested = ($data['payment_status'] ?? 'unpaid') === 'paid' ? $total : (float) ($data['paid_amount'] ?? 0);

        $paid = round(min(max($requested, 0), $total), 2);
        $remaining = max(0, round($total - $paid, 2));
        $status = $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid');

        return [$paid, $remaining, $status];
    }

    /**
     * Block (or only warn, per company setting) sales above the customer's credit limit.
     */
    protected function checkCreditLimit(Customer $customer, float $newDebt): void
    {
        $limit = (float) ($customer->credit_limit ?? 0);
        if ($limit <= 0) {
            return;
        }

        $exposure = (float) $customer->current_balance + $newDebt;
        if ($exposure > $limit + 0.005 && $this->tenantManager->getCompany()?->setting('credit_limit_action', 'block') !== 'warn') {
            throw ValidationException::withMessages([
                'customer_id' => 'تجاوز الحد الائتماني للعميل: الرصيد بعد الفاتورة '.number_format($exposure, 2).' والحد المسموح '.number_format($limit, 2).'.',
            ]);
        }
    }

    /**
     * E-invoice stamp: phase 1 QR, or signed phase 2 XML when the company has
     * an onboarded EGS device (see App\Services\Zatca\EInvoiceManager).
     */
    public function stamp(Invoice $invoice, $company = null): void
    {
        app(EInvoiceManager::class)->stamp($invoice);
    }

    public function stampCreditNote(CreditNote $note): void
    {
        app(EInvoiceManager::class)->stamp($note);
    }
}

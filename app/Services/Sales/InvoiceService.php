<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Accounting\DocumentPoster;
use App\Services\Documents\DocumentNumberService;
use App\Services\Inventory\DocumentStock;
use App\Services\TenantManager;
use App\Services\Zatca\EInvoiceManager;
use App\Services\ZatcaService;
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
     * @param  array  $options  issue_stock (default true), cost (known cost when stock already issued), check_credit (default true)
     */
    public function create(array $data, array $lines, array $options = []): Invoice
    {
        $company = $this->tenantManager->getCompany();

        return DB::transaction(function () use ($data, $lines, $options, $company) {
            [$items, $subtotal, $taxAmount] = $this->computeLines($lines);
            $total = round($subtotal + $taxAmount, 2);

            [$paidAmount, $remaining, $status] = $this->payment($data, $total);

            $customer = Customer::lockForUpdate()->findOrFail($data['customer_id']);
            if (($options['check_credit'] ?? true) && $remaining > 0) {
                $this->checkCreditLimit($customer, $remaining);
            }

            $date = Carbon::parse($data['invoice_date']);
            $isSimplified = ($data['invoice_type'] ?? null) === 'simplified';

            $invoice = Invoice::create([
                'branch_id' => $data['branch_id'] ?? auth()->user()?->branch_id ?? Branch::orderBy('id')->value('id'),
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'salesperson_id' => $data['salesperson_id'] ?? $customer->salesperson_id ?? auth()->id(),
                'invoice_number' => $this->numbers->next($data['number_type'] ?? ($isSimplified ? 'pos_invoice' : 'sales_invoice'), $date),
                'invoice_type' => $isSimplified ? 'simplified' : 'standard',
                'reference_number' => $data['reference_number'] ?? null,
                'invoice_date' => $date->toDateString(),
                'due_date' => $data['due_date'] ?? ($customer->payment_terms_days ? $date->copy()->addDays($customer->payment_terms_days)->toDateString() : null),
                'supply_date' => $data['supply_date'] ?? $date->toDateString(),
                'subtotal' => $subtotal,
                'discount_type' => 'fixed',
                'discount_value' => 0,
                'discount_amount' => round(array_sum(array_column($items, 'discount')), 2),
                'tax_rate' => 15.00,
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
                'zatca_status' => 'pending',
            ]);

            foreach ($items as $item) {
                $invoice->items()->create($item);
            }

            if ($remaining > 0) {
                $customer->increment('current_balance', $remaining);
            }

            // Stock leaves the warehouse at actual cost, unless it already left
            // with a delivery note (then the cost was posted on delivery).
            $cost = $options['cost'] ?? null;
            if ($options['issue_stock'] ?? true) {
                $warehouseId = $this->stock->warehouse($data['warehouse_id'] ?? null);
                $invoice->forceFill(['warehouse_id' => $warehouseId])->saveQuietly();
                $cost = $this->stock->issue($lines, $warehouseId, 'sale', $invoice, $date, $invoice->invoice_number);
            }

            $this->poster->salesInvoice($invoice, $invoice->warehouse_id, (float) ($cost ?? 0));

            $this->stamp($invoice, $company);

            return $invoice;
        });
    }

    /**
     * Normalise lines and compute line / document totals (rounded per line).
     */
    public function computeLines(array $lines): array
    {
        $items = [];
        $subtotal = 0.0;
        $taxAmount = 0.0;

        foreach ($lines as $line) {
            $qty = (float) $line['quantity'];
            $price = (float) $line['unit_price'];
            $discount = round((float) ($line['discount'] ?? 0), 2);
            $taxRate = (float) ($line['tax_rate'] ?? 15);

            if ($qty <= 0 || $price < 0 || $discount < 0) {
                throw ValidationException::withMessages(['items' => 'كميات وأسعار الأصناف يجب أن تكون موجبة.']);
            }

            $lineSubtotal = round($qty * $price - $discount, 2);
            if ($lineSubtotal < 0) {
                throw ValidationException::withMessages(['items' => 'خصم السطر لا يمكن أن يتجاوز قيمته.']);
            }

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
     * ZATCA QR (phase 1) — replaced by the signed phase 2 stamp when the
     * company is onboarded (see App\Services\Zatca).
     */
    public function stamp(Invoice $invoice, $company): void
    {
        if (class_exists(EInvoiceManager::class)) {
            app(EInvoiceManager::class)->stamp($invoice);

            return;
        }

        $invoice->forceFill([
            'zatca_qr' => ZatcaService::generateQrCode(
                $company?->name ?? '',
                $company?->tax_number ?? '',
                Carbon::parse($invoice->invoice_date)->format('Y-m-d').'T'.now()->format('H:i:s').'Z',
                $invoice->total,
                $invoice->tax_amount,
            ),
            'zatca_status' => 'generated',
        ])->saveQuietly();
    }

    /**
     * Phase 1 QR for credit notes (phase 2 signing handled by the e-invoice manager).
     */
    public function stampCreditNote(CreditNote $note): void
    {
        if (class_exists(EInvoiceManager::class)) {
            app(EInvoiceManager::class)->stamp($note);

            return;
        }

        $company = $this->tenantManager->getCompany();
        $note->forceFill(['zatca_qr' => ZatcaService::generateQrCode(
            $company?->name ?? '',
            $company?->tax_number ?? '',
            Carbon::parse($note->credit_note_date)->format('Y-m-d').'T'.now()->format('H:i:s').'Z',
            $note->total,
            $note->tax_amount,
        )])->saveQuietly();
    }
}

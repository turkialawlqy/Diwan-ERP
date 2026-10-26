<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentAllocation;
use App\Models\Payroll;
use App\Models\ProgressBilling;
use App\Models\PurchaseInvoice;
use App\Models\Revenue;
use App\Models\StockMovement;
use App\Models\SupplierPayment;
use App\Services\HR\PayrollService;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Posts journal entries for documents created before integrated posting
 * existed (upgrades / imported data). Documents already linked to an entry, or
 * with a legacy entry carrying their number as reference, are skipped, so the
 * backfill is safe to run more than once.
 */
class DocumentBackfill
{
    /** Supplier payments recorded together with their bill (covered by the bill's own paid part). */
    protected const BILL_PAYMENT_NOTE = 'سداد مرتبط بفاتورة الشراء%';

    public function __construct(
        protected DocumentPoster $poster,
        protected TenantManager $tenantManager,
    ) {}

    public function run(Company $company, ?callable $progress = null): array
    {
        return $this->tenantManager->runAs($company, function () use ($progress) {
            $summary = [];

            $jobs = [
                // Billing invoices are posted by their progress billing (contract revenue).
                'invoices' => [Invoice::query()->whereNotIn('id', ProgressBilling::whereNotNull('invoice_id')->select('invoice_id')), 'invoice_number', fn (Invoice $d) => $this->salesInvoice($d)],
                'credit_notes' => [CreditNote::query()->whereIn('status', ['approved', 'refunded']), 'credit_note_number', fn (CreditNote $d) => $this->poster->creditNote($d)],
                'customer_payments' => [CustomerPayment::where('status', '!=', 'cancelled'), 'payment_number', fn (CustomerPayment $d) => $this->poster->customerPayment($d)],
                'purchase_invoices' => [PurchaseInvoice::query(), 'invoice_number', fn (PurchaseInvoice $d) => $this->purchaseInvoice($d)],
                // Payments recorded together with their bill are covered by the bill's entry.
                'supplier_payments' => [SupplierPayment::where('status', '!=', 'cancelled')->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', self::BILL_PAYMENT_NOTE)), 'payment_number', fn (SupplierPayment $d) => $this->poster->supplierPayment($d)],
                'expenses' => [Expense::query(), 'expense_number', fn (Expense $d) => $this->poster->expense($d)],
                'revenues' => [Revenue::query(), 'revenue_number', fn (Revenue $d) => $this->poster->revenue($d)],
                // Only approved payrolls are accrued, with the entry approval posts (summary-only legacy payrolls keep the simple entry).
                'payrolls' => [Payroll::query()->whereIn('status', ['approved', 'paid']), 'payroll_number',
                    fn (Payroll $d) => $d->items()->exists() ? app(PayrollService::class)->postAccrual($d) : $this->poster->payroll($d)],
            ];

            foreach ($jobs as $name => [$query, $numberColumn, $post]) {
                $posted = 0;
                $failed = 0;

                $query->orderBy('id')->chunkById(200, function ($documents) use ($numberColumn, $post, &$posted, &$failed) {
                    foreach ($documents as $document) {
                        if ($this->alreadyPosted($document, $document->{$numberColumn})) {
                            continue;
                        }

                        try {
                            DB::transaction(fn () => $post($document));
                            $posted++;
                        } catch (AccountingException $e) {
                            $failed++;
                            report($e);
                        }
                    }
                });

                $summary[$name] = ['posted' => $posted, 'failed' => $failed];
                if ($progress) {
                    $progress($name, $posted, $failed);
                }
            }

            return $summary;
        });
    }

    /**
     * The invoice's paid part is only what was paid with it: receipts posted on
     * their own (allocations, or linked receipts without allocations) are
     * excluded, and stock already costed (delivery note or issue movement)
     * uses its recorded cost instead of an estimate.
     */
    protected function salesInvoice(Invoice $invoice): JournalEntry
    {
        $payments = CustomerPayment::where('invoice_id', $invoice->id)->where('status', '!=', 'cancelled')->whereDoesntHave('allocations')->sum('amount');
        $allocated = PaymentAllocation::where('invoice_id', $invoice->id)->sum('amount');
        $invoice->paid_amount = max(0, round((float) $invoice->paid_amount - (float) $payments - (float) $allocated, 2));

        $issued = StockMovement::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id);
        $cost = $invoice->delivery_note_id ? 0.0 : ((clone $issued)->exists() ? abs((float) $issued->sum('total_cost')) : null);

        return $this->poster->salesInvoice($invoice, $invoice->warehouse_id, $cost);
    }

    protected function purchaseInvoice(PurchaseInvoice $invoice): JournalEntry
    {
        $later = SupplierPayment::where('purchase_invoice_id', $invoice->id)->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', self::BILL_PAYMENT_NOTE))->sum('amount');
        $invoice->paid_amount = max(0, round((float) $invoice->paid_amount - (float) $later, 2));

        return $this->poster->purchaseInvoice($invoice);
    }

    protected function alreadyPosted(Model $document, ?string $number): bool
    {
        return JournalEntry::query()
            ->where(function ($q) use ($document, $number) {
                $q->where(fn ($q) => $q->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey()));
                if ($number) {
                    $q->orWhere(fn ($q) => $q->whereNull('source_type')->where('reference', $number));
                }
            })
            ->exists();
    }
}

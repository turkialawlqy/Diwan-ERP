<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\PurchaseInvoice;
use App\Models\Revenue;
use App\Models\SupplierPayment;
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
    public function __construct(
        protected DocumentPoster $poster,
        protected TenantManager $tenantManager,
    ) {}

    public function run(Company $company, ?callable $progress = null): array
    {
        return $this->tenantManager->runAs($company, function () use ($progress) {
            $summary = [];

            $jobs = [
                'invoices' => [Invoice::query(), 'invoice_number', fn (Invoice $d) => $this->poster->salesInvoice($d)],
                'credit_notes' => [CreditNote::query()->whereIn('status', ['approved', 'refunded']), 'credit_note_number', fn (CreditNote $d) => $this->poster->creditNote($d)],
                'customer_payments' => [CustomerPayment::query(), 'payment_number', fn (CustomerPayment $d) => $this->poster->customerPayment($d)],
                'purchase_invoices' => [PurchaseInvoice::query(), 'invoice_number', fn (PurchaseInvoice $d) => $this->poster->purchaseInvoice($d)],
                // Payments recorded together with their bill are covered by the bill's entry.
                'supplier_payments' => [SupplierPayment::query()->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', 'سداد مرتبط بفاتورة الشراء%')), 'payment_number', fn (SupplierPayment $d) => $this->poster->supplierPayment($d)],
                'expenses' => [Expense::query(), 'expense_number', fn (Expense $d) => $this->poster->expense($d)],
                'revenues' => [Revenue::query(), 'revenue_number', fn (Revenue $d) => $this->poster->revenue($d)],
                'payrolls' => [Payroll::query(), 'payroll_number', fn (Payroll $d) => $this->poster->payroll($d)],
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

<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\Customer;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRun;
use App\Services\Documents\DocumentSharing;
use App\Services\TenantManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Issues due recurring invoices. Each period is claimed by a unique run row
 * inside the same transaction as the invoice, so running twice (or on two
 * servers) can never bill a period twice. Missed periods are caught up.
 */
class RecurringInvoiceRunner
{
    public function __construct(protected TenantManager $tenants, protected InvoiceService $invoices) {}

    public function runDue(?Carbon $today = null): int
    {
        $today ??= now();
        $count = 0;

        RecurringInvoice::withoutGlobalScopes()
            ->where('status', 'active')
            ->where('next_run_date', '<=', $today->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($templates) use ($today, &$count) {
                foreach ($templates as $template) {
                    $company = Company::find($template->company_id);
                    if (! $company || $company->status === 'suspended') {
                        continue;
                    }
                    $count += $this->tenants->runAs($company, fn () => $this->runTemplate($template, $today));
                }
            });

        return $count;
    }

    public function runTemplate(RecurringInvoice $template, ?Carbon $today = null): int
    {
        $today ??= now();
        $count = 0;

        while ($template->status === 'active' && $template->next_run_date->lte($today) && ! $template->isFinished()) {
            $period = $template->next_run_date->copy();

            try {
                $invoice = DB::transaction(function () use ($template, $period) {
                    $run = RecurringInvoiceRun::create(['recurring_invoice_id' => $template->id, 'period_date' => $period->toDateString()]);
                    $customer = Customer::find($template->customer_id);

                    $invoice = $this->invoices->create([
                        'customer_id' => $template->customer_id,
                        'branch_id' => $template->branch_id,
                        'invoice_date' => $period->toDateString(),
                        'supply_date' => $period->toDateString(),
                        'due_date' => $period->copy()->addDays($template->due_days ?? (int) ($customer?->payment_terms_days ?? 0))->toDateString(),
                        'invoice_type' => $template->invoice_type,
                        'payment_method' => 'credit',
                        'payment_status' => 'unpaid',
                        'currency_code' => $template->currency_code,
                        'notes' => trim(($template->notes ? $template->notes."\n" : '').'فاتورة دورية: '.$template->name.' — '.$period->format('Y-m')),
                    ], $template->lines, ['issue_stock' => false, 'check_credit' => false]);

                    $run->update(['invoice_id' => $invoice->id]);

                    return $invoice;
                });
            } catch (UniqueConstraintViolationException) {
                // Another process already billed this period: just move on.
                $invoice = null;
            } catch (Throwable $e) {
                $template->update(['last_error' => mb_substr($e->getMessage(), 0, 1000)]);
                report($e);

                return $count;
            }

            $template->generated_count += $invoice ? 1 : 0;
            $template->next_run_date = $template->nextAfter($period);
            $template->last_error = null;
            if ($template->isFinished()) {
                $template->status = 'ended';
            }
            $template->save();

            if ($invoice) {
                $count++;
                if ($template->auto_send && ($to = $template->send_to ?: $invoice->customer?->email)) {
                    app(DocumentSharing::class)->email($invoice, $to, null, null, $template->created_by);
                }
            }
        }

        return $count;
    }
}

<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\RecurringJournal;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates due recurring journal entries (rent, accruals, ...).
 */
class RecurringJournalRunner
{
    public function __construct(
        protected PostingService $posting,
        protected TenantManager $tenantManager,
    ) {}

    public function runDue(?Carbon $today = null): int
    {
        $today ??= now();
        $count = 0;

        RecurringJournal::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereDate('next_run_date', '<=', $today->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($templates) use ($today, &$count) {
                foreach ($templates as $template) {
                    $company = Company::find($template->company_id);
                    if (! $company || $company->status === 'suspended') {
                        continue;
                    }

                    // One failing template (closed period, deleted account...) is recorded and skipped; the others still run.
                    try {
                        $this->tenantManager->runAs($company, function () use ($template, $today, &$count) {
                            $this->runTemplate($template, $today, $count);
                        });
                    } catch (\Throwable $e) {
                        report($e);
                        $template->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000), 'last_error_at' => now()])->saveQuietly();
                    }
                }
            });

        return $count;
    }

    protected function runTemplate(RecurringJournal $template, Carbon $today, int &$count): void
    {
        while ($template->is_active && $template->next_run_date->lte($today)) {
            DB::transaction(function () use ($template) {
                $entry = $this->posting->post([
                    'date' => $template->next_run_date,
                    'description' => $template->description,
                    'entry_type' => 'recurring',
                    'source' => $template,
                    'created_by' => $template->created_by,
                    'lines' => $template->lines,
                ]);
                // Accruals are undone the next day, when the real document is booked.
                if ($template->auto_reverse) {
                    $this->posting->reverse($entry, $template->next_run_date->copy()->addDay(), 'عكس تلقائي - '.$template->description);
                }

                $next = match ($template->frequency) {
                    'quarterly' => $template->next_run_date->copy()->addMonthsNoOverflow(3),
                    'yearly' => $template->next_run_date->copy()->addYear(),
                    default => $template->next_run_date->copy()->addMonthNoOverflow(),
                };

                $template->next_run_date = $next;
                $template->last_run_at = now();
                $template->last_error = null;
                $template->last_error_at = null;
                if ($template->end_date && $next->gt($template->end_date)) {
                    $template->is_active = false;
                }
                $template->save();
            });
            $count++;
        }
    }
}

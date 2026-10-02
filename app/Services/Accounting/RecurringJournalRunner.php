<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\RecurringJournal;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;

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
            ->where('next_run_date', '<=', $today->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($templates) use ($today, &$count) {
                foreach ($templates as $template) {
                    $company = Company::find($template->company_id);
                    if (! $company || $company->status === 'suspended') {
                        continue;
                    }

                    $this->tenantManager->runAs($company, function () use ($template, $today, &$count) {
                        while ($template->is_active && $template->next_run_date->lte($today)) {
                            $this->posting->post([
                                'date' => $template->next_run_date,
                                'description' => $template->description,
                                'entry_type' => 'recurring',
                                'source' => $template,
                                'created_by' => $template->created_by,
                                'lines' => $template->lines,
                            ]);
                            $count++;

                            $next = match ($template->frequency) {
                                'quarterly' => $template->next_run_date->copy()->addMonthsNoOverflow(3),
                                'yearly' => $template->next_run_date->copy()->addYear(),
                                default => $template->next_run_date->copy()->addMonthNoOverflow(),
                            };

                            $template->next_run_date = $next;
                            $template->last_run_at = now();
                            if ($template->end_date && $next->gt($template->end_date)) {
                                $template->is_active = false;
                            }
                            $template->save();
                        }
                    });
                }
            });

        return $count;
    }
}

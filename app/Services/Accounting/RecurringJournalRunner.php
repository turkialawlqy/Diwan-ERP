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
        while ($this->postNextPeriod($template, $today)) {
            $count++;
        }
    }

    /**
     * Each period is claimed under the template's lock, in the same transaction as its entry: a second run
     * (a retry, a second server) waits here, finds the date already moved on and posts nothing.
     */
    protected function postNextPeriod(RecurringJournal $template, Carbon $today): bool
    {
        return DB::transaction(function () use ($template, $today) {
            $template = RecurringJournal::withoutGlobalScopes()->whereKey($template->id)->lockForUpdate()->first();
            if (! $template || ! $template->is_active || $template->next_run_date->gt($today)) {
                return false;
            }

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

            $next = $template->nextAfter($template->next_run_date);

            $template->next_run_date = $next;
            $template->last_run_at = now();
            $template->last_error = null;
            $template->last_error_at = null;
            if ($template->end_date && $next->gt($template->end_date)) {
                $template->is_active = false;
            }
            $template->save();

            return true;
        });
    }
}

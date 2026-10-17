<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fiscal year end: transfers the year's revenue and expense balances to
 * retained earnings with a closing entry, then closes every period.
 */
class YearEndClosing
{
    public function __construct(
        protected AccountingReports $reports,
        protected PostingService $posting,
        protected AccountMap $map,
    ) {}

    public function close(FiscalYear $year): ?JournalEntry
    {
        if ($year->isClosed()) {
            throw new AccountingException('السنة المالية مغلقة مسبقاً.');
        }
        if (Carbon::parse($year->end_date)->endOfDay()->isFuture()) {
            throw new AccountingException('لا يمكن إقفال سنة مالية قبل انتهائها.');
        }
        $earlier = FiscalYear::where('company_id', $year->company_id)->whereDate('end_date', '<', $year->start_date)->where('status', '!=', 'closed')->orderBy('start_date')->first();
        if ($earlier) {
            throw new AccountingException("يجب إقفال السنة المالية السابقة ({$earlier->name}) أولاً.");
        }

        $drafts = JournalEntry::where('status', JournalEntry::STATUS_DRAFT)
            ->whereBetween('date', [$year->start_date->toDateString(), $year->end_date->toDateString().' 23:59:59'])
            ->count();
        if ($drafts) {
            throw new AccountingException("يوجد {$drafts} قيد مسودة داخل السنة المالية. يجب ترحيلها أو حذفها قبل الإقفال.");
        }

        return DB::transaction(function () use ($year) {
            $totals = $this->reports->totalsBetween(Carbon::parse($year->start_date), Carbon::parse($year->end_date));
            $accounts = Account::whereIn('type', ['revenue', 'expense'])->where('is_parent', false)->get()->keyBy('id');

            $lines = [];
            $net = 0.0;
            foreach ($accounts as $account) {
                $t = $totals[$account->id] ?? null;
                if (! $t) {
                    continue;
                }
                $balance = round($t['debit'] - $t['credit'], 2); // positive = debit balance
                if ($balance == 0.0) {
                    continue;
                }
                // Zero the account: post the opposite side.
                $lines[] = [
                    'account_id' => $account->id,
                    'debit' => $balance < 0 ? -$balance : 0,
                    'credit' => $balance > 0 ? $balance : 0,
                    'description' => 'إقفال '.$account->name_ar,
                ];
                $net += $balance;
            }

            $entry = null;
            if ($lines) {
                // Debit balances (expenses) exceed credits => loss.
                $lines[] = [
                    'role' => 'retained_earnings',
                    'debit' => $net > 0 ? $net : 0,
                    'credit' => $net < 0 ? -$net : 0,
                    'description' => $net < 0 ? 'صافي ربح السنة' : 'صافي خسارة السنة',
                ];

                $entry = $this->posting->post([
                    'date' => $year->end_date,
                    'description' => "قيد إقفال السنة المالية {$year->name}",
                    'entry_type' => 'closing',
                    'source' => $year,
                    'lines' => $lines,
                ]);
            }

            // Open periods become closed; locked periods stay locked.
            FiscalPeriod::where('fiscal_year_id', $year->id)->where('status', 'open')->update(['status' => 'closed']);
            $year->update([
                'status' => 'closed',
                'closing_entry_id' => $entry?->id,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
            ]);

            return $entry;
        });
    }

    /**
     * Re-open a closed year (reverses the closing entry). Locked periods block it.
     */
    public function reopen(FiscalYear $year): void
    {
        if (! $year->isClosed()) {
            return;
        }

        if (FiscalPeriod::where('fiscal_year_id', $year->id)->where('status', 'locked')->exists()) {
            throw new AccountingException('لا يمكن إعادة فتح سنة تحتوي على فترات مقفلة نهائياً.');
        }

        DB::transaction(function () use ($year) {
            FiscalPeriod::where('fiscal_year_id', $year->id)->update(['status' => 'open']);

            if ($year->closing_entry_id && ($entry = JournalEntry::find($year->closing_entry_id))) {
                $this->posting->reverse($entry, $year->end_date, "إلغاء إقفال السنة المالية {$year->name}");
            }

            $year->update(['status' => 'open', 'closing_entry_id' => null, 'closed_at' => null, 'closed_by' => null]);
        });
    }
}

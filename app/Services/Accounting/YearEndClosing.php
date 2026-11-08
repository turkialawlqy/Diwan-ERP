<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\FiscalYearClosing;
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

    /** Branches closed / skipped by the last close() call: [['branch_id', 'name', 'entry']...]. */
    public array $report = ['closed' => [], 'skipped' => []];

    /**
     * Per-branch readiness for closing: draft entries inside the year block a branch.
     *
     * @return array<int, array{branch_id: ?int, name: string, drafts: int, net: float, closed: bool, ready: bool}>
     */
    public function branchStatus(FiscalYear $year): array
    {
        $range = [$year->start_date->toDateString(), $year->end_date->toDateString().' 23:59:59'];
        $closed = FiscalYearClosing::withoutGlobalScopes()->where('fiscal_year_id', $year->id)->pluck('closing_entry_id', 'branch_id');
        $drafts = JournalEntry::withoutGlobalScopes()->where('company_id', $year->company_id)->where('status', JournalEntry::STATUS_DRAFT)->whereBetween('date', $range)->selectRaw('branch_id, COUNT(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');

        $rows = [];
        $branches = Branch::withoutGlobalScopes()->where('company_id', $year->company_id)->whereNull('deleted_at')->orderByDesc('is_main')->orderBy('id')->get(['id', 'name_ar', 'name_en']);
        foreach ($branches as $branch) {
            $rows[] = $this->row($year->company_id, $branch->id, $branch->name, $range, (int) ($drafts[$branch->id] ?? 0), $closed->has($branch->id));
        }
        // Entries not tied to any branch are closed with their own entry.
        $unassigned = (int) ($drafts[''] ?? $drafts[null] ?? 0);
        $hasNull = JournalEntry::withoutGlobalScopes()->where('company_id', $year->company_id)->whereNull('branch_id')->whereBetween('date', $range)->exists();
        if ($hasNull || $unassigned) {
            $rows[] = $this->row($year->company_id, null, 'بدون فرع', $range, $unassigned, $closed->has(''));
        }

        return $rows;
    }

    protected function row(int $companyId, ?int $branchId, string $name, array $range, int $drafts, bool $closed): array
    {
        $net = 0.0;
        foreach ($this->branchBalances($companyId, $branchId, $range) as $balance) {
            $net += $balance['balance'];
        }

        return ['branch_id' => $branchId, 'name' => $name, 'drafts' => $drafts, 'net' => round(-$net, 2), 'closed' => $closed, 'ready' => ! $closed && $drafts === 0];
    }

    /** Warnings that do not block the closing. */
    public function warnings(FiscalYear $year): array
    {
        $warnings = [];
        $outside = JournalEntry::withoutGlobalScopes()->where('company_id', $year->company_id)->whereIn('status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->whereDate('date', '<', $year->start_date->toDateString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fiscal_years as y')->whereColumn('y.company_id', 'journal_entries.company_id')
                ->whereColumn('y.start_date', '<=', 'journal_entries.date')->whereColumn('y.end_date', '>=', 'journal_entries.date'))
            ->count();
        if ($outside) {
            $warnings[] = "يوجد {$outside} قيد مرحّل مؤرخ قبل بداية السنة المالية وخارج أي سنة معرّفة؛ لن يدخل في نتيجة هذه السنة.";
        }

        return $warnings;
    }

    /**
     * Close the year branch by branch (a closing entry per branch). $mode 'all': every
     * branch must be ready or nothing is closed; 'passed': ready branches are closed and
     * the rest skipped (the year stays open until the remaining branches close later).
     */
    public function close(FiscalYear $year, string $mode = 'all'): ?JournalEntry
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

        $pending = array_values(array_filter($this->branchStatus($year), fn ($b) => ! $b['closed']));
        $blocked = array_values(array_filter($pending, fn ($b) => ! $b['ready']));
        $ready = array_values(array_filter($pending, fn ($b) => $b['ready']));

        if ($blocked && $mode !== 'passed') {
            $names = implode('، ', array_map(fn ($b) => "{$b['name']} ({$b['drafts']} مسودة)", $blocked));
            throw new AccountingException("يوجد قيود مسودة داخل السنة المالية في: {$names}. يجب ترحيلها أو حذفها قبل الإقفال.");
        }
        if (! $ready && $pending) {
            throw new AccountingException('لا يوجد فرع جاهز للإقفال: كل الفروع المتبقية فيها قيود مسودة.');
        }

        $this->report = ['closed' => [], 'skipped' => array_map(fn ($b) => $b['name'], $blocked)];

        return DB::transaction(function () use ($year, $ready, $blocked) {
            $first = null;
            foreach ($ready as $branch) {
                $entry = $this->closeBranch($year, $branch['branch_id']);
                $first ??= $entry;
                FiscalYearClosing::create([
                    'company_id' => $year->company_id, 'fiscal_year_id' => $year->id, 'branch_id' => $branch['branch_id'],
                    'closing_entry_id' => $entry?->id, 'closed_at' => now(), 'closed_by' => auth()->id(),
                ]);
                $this->report['closed'][] = ['branch_id' => $branch['branch_id'], 'name' => $branch['name'], 'entry' => $entry?->entry_number];
            }

            // Every branch closed: the year closes and its periods with it.
            if (! $blocked) {
                FiscalPeriod::where('fiscal_year_id', $year->id)->where('status', 'open')->update(['status' => 'closed']);
                $year->update([
                    'status' => 'closed',
                    'closing_entry_id' => FiscalYearClosing::where('fiscal_year_id', $year->id)->whereNotNull('closing_entry_id')->orderBy('id')->value('closing_entry_id'),
                    'closed_at' => now(),
                    'closed_by' => auth()->id(),
                ]);
            }

            return $first;
        });
    }

    /** Revenue / expense account balances of one branch inside the year: account_id => [account, balance (debit - credit)]. */
    protected function branchBalances(int $companyId, ?int $branchId, array $range): array
    {
        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.company_id', $companyId)
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->where('e.entry_type', '!=', 'closing')
            // Reopening a year reverses its closing entry: the two are one pair and neither is the year's activity.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journal_entries as closing')->whereColumn('closing.id', 'e.reversal_of_id')->where('closing.entry_type', 'closing'))
            ->whereIn('a.type', ['revenue', 'expense'])->where('a.is_parent', false)
            ->when($branchId, fn ($q) => $q->where('e.branch_id', $branchId), fn ($q) => $q->whereNull('e.branch_id'))
            ->where('l.date', '>=', $range[0])->where('l.date', '<=', $range[1])
            ->groupBy('l.account_id', 'a.name_ar')
            ->selectRaw('l.account_id, a.name_ar, ROUND(SUM(l.debit) - SUM(l.credit), 2) as balance')
            ->get()->filter(fn ($r) => (float) $r->balance != 0.0)
            ->mapWithKeys(fn ($r) => [$r->account_id => ['name' => $r->name_ar, 'balance' => (float) $r->balance]])->all();
    }

    protected function closeBranch(FiscalYear $year, ?int $branchId): ?JournalEntry
    {
        $range = [$year->start_date->toDateString(), $year->end_date->toDateString().' 23:59:59'];
        $lines = [];
        $net = 0.0;
        foreach ($this->branchBalances($year->company_id, $branchId, $range) as $accountId => $row) {
            $balance = $row['balance']; // positive = debit balance
            $lines[] = [
                'account_id' => $accountId,
                'debit' => $balance < 0 ? -$balance : 0,
                'credit' => $balance > 0 ? $balance : 0,
                'description' => 'إقفال '.$row['name'],
            ];
            $net += $balance;
        }
        if (! $lines) {
            return null;
        }

        // Debit balances (expenses) exceed credits => loss.
        $lines[] = [
            'role' => 'retained_earnings',
            'debit' => $net > 0 ? $net : 0,
            'credit' => $net < 0 ? -$net : 0,
            'description' => $net < 0 ? 'صافي ربح السنة' : 'صافي خسارة السنة',
        ];

        return $this->posting->post([
            'date' => $year->end_date,
            'description' => "قيد إقفال السنة المالية {$year->name}",
            'entry_type' => 'closing',
            'source' => $year,
            'branch_id' => $branchId,
            'lines' => $lines,
        ]);
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

        // A later closed year was closed on top of this one's result (retained earnings): reopen the later one first.
        if (FiscalYear::where('company_id', $year->company_id)->where('start_date', '>', $year->end_date)->where('status', 'closed')->exists()) {
            throw new AccountingException('لا يمكن إعادة فتح هذه السنة لأن سنة لاحقة مقفلة؛ أعد فتح السنة اللاحقة أولاً.');
        }

        DB::transaction(function () use ($year) {
            FiscalPeriod::where('fiscal_year_id', $year->id)->update(['status' => 'open']);

            $entryIds = FiscalYearClosing::withoutGlobalScopes()->where('fiscal_year_id', $year->id)->pluck('closing_entry_id')->push($year->closing_entry_id)->filter()->unique();
            foreach ($entryIds as $entryId) {
                if (($entry = JournalEntry::withoutGlobalScopes()->find($entryId)) && $entry->status === JournalEntry::STATUS_POSTED) {
                    $this->posting->reverse($entry, $year->end_date, "إلغاء إقفال السنة المالية {$year->name}");
                }
            }
            FiscalYearClosing::where('fiscal_year_id', $year->id)->delete();

            $year->update(['status' => 'open', 'closing_entry_id' => null, 'closed_at' => null, 'closed_by' => null]);
        });
    }
}

<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly totals per account and branch (branch_account_balances): what account_balances
 * is for the whole company, for reports narrowed to some branches. Like it, the table is
 * written in the posting transaction and can be rebuilt from the ledger lines at any time
 * (php artisan erp:accounting rebuild-balances).
 *
 * Entries without a branch are kept under branch 0, so the branches add up to
 * account_balances; a branch's report leaves those entries out, so none reads them.
 */
class BranchBalances
{
    protected const TABLE = 'branch_account_balances';

    protected const NO_BRANCH = 0;

    /**
     * Add amounts to one month of a branch: account_id => ['debit' => x, 'credit' => y], of
     * either sign. One statement whatever the number of accounts, in account order (a stable
     * lock order), and two postings opening the same month cannot collide.
     */
    public function add(int $companyId, ?int $branchId, int $period, array $amounts): void
    {
        ksort($amounts);
        $now = now();
        $rows = [];
        foreach ($amounts as $accountId => $amount) {
            $rows[] = [
                'company_id' => $companyId, 'branch_id' => $branchId ?: self::NO_BRANCH, 'account_id' => $accountId, 'period' => $period,
                // Fixed-point text (never scientific notation), as for account_balances.
                'debit' => number_format((float) $amount['debit'], 2, '.', ''),
                'credit' => number_format((float) $amount['credit'], 2, '.', ''),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $incoming = fn (string $column) => DB::getDriverName() === 'sqlite' ? "excluded.{$column}" : "VALUES({$column})";

        DB::table(self::TABLE)->upsert($rows, ['company_id', 'branch_id', 'account_id', 'period'], [
            'debit' => DB::raw('debit + '.$incoming('debit')),
            'credit' => DB::raw('credit + '.$incoming('credit')),
            'updated_at' => $now,
        ]);
    }

    /**
     * A posted entry filed under another branch takes its amounts along. (A move made
     * outside the model, in raw SQL, is put right by the rebuild.)
     */
    public function moveEntry(JournalEntry $entry, ?int $from, ?int $to): void
    {
        $months = [];
        foreach (DB::table('journal_entry_lines')->where('journal_entry_id', $entry->id)->whereNotNull('date')->get(['account_id', 'date', 'debit', 'credit']) as $line) {
            $period = (int) Carbon::parse($line->date)->format('Ym');
            $months[$period][$line->account_id]['debit'] = ($months[$period][$line->account_id]['debit'] ?? 0) + (float) $line->debit;
            $months[$period][$line->account_id]['credit'] = ($months[$period][$line->account_id]['credit'] ?? 0) + (float) $line->credit;
        }

        DB::transaction(function () use ($entry, $from, $to, $months) {
            foreach ($months as $period => $amounts) {
                $this->add($entry->company_id, $to, $period, $amounts);
                $this->add($entry->company_id, $from, $period, array_map(fn ($a) => ['debit' => -$a['debit'], 'credit' => -$a['credit']], $amounts));

                // Nothing left of that month in the old branch: as if it had never been there.
                DB::table(self::TABLE)
                    ->where(['company_id' => $entry->company_id, 'branch_id' => $from ?: self::NO_BRANCH, 'period' => $period])
                    ->whereIn('account_id', array_keys($amounts))
                    ->whereRaw('ABS(debit) < 0.005 AND ABS(credit) < 0.005')
                    ->delete();
            }
        });
    }

    /** Rebuild a company's totals from its ledger lines (in one statement, however long the ledger is). */
    public function rebuild(int $companyId): void
    {
        // Upgrading an older install, an earlier migration rebuilds the balances (2026_10_03_000005) before
        // the one that creates this table has run; that one fills it from the ledger.
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::table(self::TABLE)->where('company_id', $companyId)->delete();

        $period = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y%m', l.date) AS INTEGER)"
            : "CAST(DATE_FORMAT(l.date, '%Y%m') AS UNSIGNED)";

        DB::table(self::TABLE)->insertUsing(
            ['company_id', 'branch_id', 'account_id', 'period', 'debit', 'credit', 'created_at', 'updated_at'],
            DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.company_id', $companyId)
                ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
                ->whereNotNull('l.date')
                ->groupBy(DB::raw('COALESCE(e.branch_id, 0)'), 'l.account_id', DB::raw($period))
                ->selectRaw("? as company_id, COALESCE(e.branch_id, 0) as branch_id, l.account_id, {$period} as period, ROUND(SUM(l.debit), 2) as debit, ROUND(SUM(l.credit), 2) as credit, ? as created_at, ? as updated_at", [$companyId, now(), now()]),
        );
    }
}

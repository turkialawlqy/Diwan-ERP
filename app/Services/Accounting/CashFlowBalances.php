<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly totals per branch and "shape" of entry (cash_flow_balances), so the cash flow
 * statement does not have to read every line of the period.
 *
 * The statement keeps an entry when it has a line on a cash account, a line on another
 * account, and its cash lines do not net to zero. Which accounts are cash is only known when
 * the report runs: the bank boxes (for a branch's report, that branch's boxes), the cash
 * category and the account map can all change, and each change reclassifies past entries. So
 * nothing stored here depends on them: entries are grouped by what decides their fate for
 * ANY set of cash accounts, their shape, and the set is applied when the report is read.
 *
 * A shape is the entry's accounts and which proper combinations of them net to zero (a cash
 * sale with its cost: cogs and inventory cancel out), as bitmasks over the sorted accounts:
 * "4,36,55", or "5,38,75,76|5,10". Entries of one shape are kept or left out together, whatever
 * the cash accounts are. An entry too wide to enumerate is kept on its own ("#<entry id>") and
 * decided from its own amounts.
 *
 * Like account_balances, the table is written in the posting transaction and can be rebuilt
 * from the ledger lines at any time (php artisan erp:accounting rebuild-balances). Entries
 * without a branch are kept under branch 0; a branch's report leaves them out, as it does their lines.
 */
class CashFlowBalances
{
    protected const TABLE = 'cash_flow_balances';

    protected const NO_BRANCH = 0;

    /** Wider entries are kept one by one: every combination of their accounts would have to be tried. */
    protected const MAX_ACCOUNTS = 10;

    protected const MAX_SHAPE_LENGTH = 190;

    /** A posted entry joins the totals of its month, in the posting transaction. */
    public function add(JournalEntry $entry, array $lines): void
    {
        $amounts = $this->amountsByAccount($lines);

        $this->write($entry->company_id, $entry->branch_id, $this->periodOf($entry->date->toDateString()), $entry->entry_type === 'opening', $this->shapeOf($entry->id, $amounts), $amounts);
    }

    /**
     * A posted entry filed under another branch takes its amounts along. (A move made
     * outside the model, in raw SQL, is put right by the rebuild.)
     */
    public function moveEntry(JournalEntry $entry, ?int $from, ?int $to): void
    {
        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $entry->id)->whereNotNull('date')->get(['account_id', 'date', 'debit', 'credit']);
        if ($lines->isEmpty()) {
            return;
        }

        $shape = $this->shapeOf($entry->id, $this->amountsByAccount($lines));
        $opening = $entry->entry_type === 'opening';

        DB::transaction(function () use ($entry, $from, $to, $lines, $shape, $opening) {
            foreach ($lines->groupBy(fn ($line) => $this->periodOf($line->date)) as $period => $monthLines) {
                $amounts = $this->amountsByAccount($monthLines);
                $this->write($entry->company_id, $to, $period, $opening, $shape, $amounts);
                $this->write($entry->company_id, $from, $period, $opening, $shape, array_map(fn ($a) => ['debit' => -$a['debit'], 'credit' => -$a['credit']], $amounts));

                // Nothing left of that month in the old branch: as if it had never been there.
                DB::table(self::TABLE)
                    ->where(['company_id' => $entry->company_id, 'branch_id' => $from ?: self::NO_BRANCH, 'period' => $period, 'is_opening' => $opening, 'shape' => $shape])
                    ->whereIn('account_id', array_keys($amounts))
                    ->whereRaw('ABS(debit) < 0.005 AND ABS(credit) < 0.005')
                    ->delete();
            }
        });
    }

    /** Rebuild a company's totals from its ledger lines, entry by entry (the shape needs each entry's lines together). */
    public function rebuild(int $companyId): void
    {
        // Upgrading an older install, an earlier migration rebuilds the balances (2026_10_03_000005) before
        // the one that creates this table has run; that one fills it from the ledger.
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::table(self::TABLE)->where('company_id', $companyId)->delete();

        // Summed in halalas (no float drift, however long the ledger is): branch|period|opening|shape => account => [debit, credit].
        $totals = [];
        DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->whereIn('status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->select(['id', 'branch_id', 'entry_type'])
            ->chunkById(2000, function ($entries) use (&$totals) {
                $lines = DB::table('journal_entry_lines')
                    ->whereIn('journal_entry_id', $entries->pluck('id'))
                    ->whereNotNull('date')
                    ->get(['journal_entry_id', 'account_id', 'date', 'debit', 'credit'])
                    ->groupBy('journal_entry_id');

                foreach ($entries as $entry) {
                    if (! isset($lines[$entry->id])) {
                        continue;
                    }
                    $shape = $this->shapeOf($entry->id, $this->amountsByAccount($lines[$entry->id]));
                    foreach ($lines[$entry->id] as $line) {
                        $key = implode('|', [$entry->branch_id ?: self::NO_BRANCH, $this->periodOf($line->date), $entry->entry_type === 'opening' ? 1 : 0, $shape]);
                        $totals[$key][$line->account_id][0] = ($totals[$key][$line->account_id][0] ?? 0) + (int) round((float) $line->debit * 100);
                        $totals[$key][$line->account_id][1] = ($totals[$key][$line->account_id][1] ?? 0) + (int) round((float) $line->credit * 100);
                    }
                }
            });

        $rows = [];
        $now = now();
        foreach ($totals as $key => $accounts) {
            [$branchId, $period, $opening, $shape] = explode('|', $key, 4);
            foreach ($accounts as $accountId => [$debit, $credit]) {
                $rows[] = ['company_id' => $companyId, 'branch_id' => (int) $branchId, 'period' => (int) $period, 'is_opening' => (int) $opening, 'shape' => $shape, 'account_id' => $accountId,
                    'debit' => number_format($debit / 100, 2, '.', ''), 'credit' => number_format($credit / 100, 2, '.', ''), 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(self::TABLE)->insert($chunk);
        }
    }

    /**
     * The statement's totals for whole months: the lines of the entries that moved cash, per
     * opening flag and account, as the line-based query answers them. Null when this table has
     * nothing for those months or does not add up to the monthly balances (a ledger filled
     * outside the posting service): the caller reads the lines, as it does for partial months.
     *
     * @param  array<int>|null  $branchIds  null: the whole company
     * @param  array<int>  $cashIds
     * @return Collection<int, object{is_opening: int, account_id: int, debit: float, credit: float}>|null
     */
    public function totals(int $companyId, ?array $branchIds, int $firstPeriod, int $lastPeriod, array $cashIds): ?Collection
    {
        $scope = fn ($query) => $query->where('company_id', $companyId)
            ->whereBetween('period', [$firstPeriod, $lastPeriod])
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds));

        $sums = fn (string $table) => $scope(DB::table($table))->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')->first();
        $own = $sums(self::TABLE);
        $ledger = $sums('branch_account_balances');
        if ((float) $own->debit === 0.0 || abs((float) $own->debit - (float) $ledger->debit) >= 0.005 || abs((float) $own->credit - (float) $ledger->credit) >= 0.005) {
            return null;
        }

        $shapes = $scope(DB::table(self::TABLE))
            ->groupBy('shape', 'is_opening', 'account_id')
            ->selectRaw('shape, is_opening, account_id, SUM(debit) as debit, SUM(credit) as credit')
            ->get()
            ->groupBy('shape');

        $cash = array_flip($cashIds);
        $totals = [];
        foreach ($shapes as $shape => $rows) {
            if (! $this->movesCash((string) $shape, $rows, $cash)) {
                continue;
            }
            foreach ($rows as $row) {
                $key = (int) $row->is_opening.'|'.$row->account_id;
                // In halalas: thousands of shapes can add up to one account.
                $totals[$key]['debit'] = ($totals[$key]['debit'] ?? 0) + (int) round((float) $row->debit * 100);
                $totals[$key]['credit'] = ($totals[$key]['credit'] ?? 0) + (int) round((float) $row->credit * 100);
            }
        }

        return collect($totals)->map(function ($amounts, $key) {
            [$opening, $accountId] = explode('|', (string) $key);

            return (object) ['is_opening' => (int) $opening, 'account_id' => (int) $accountId, 'debit' => $amounts['debit'] / 100, 'credit' => $amounts['credit'] / 100];
        })->values();
    }

    /**
     * Does an entry of this shape count for these cash accounts? A line on a cash account, a line
     * on another account, and cash lines that do not net to zero.
     *
     * @param  Collection<int, object>  $rows  the shape's rows
     * @param  array<int, mixed>  $cash  flipped list of cash accounts
     */
    protected function movesCash(string $shape, Collection $rows, array $cash): bool
    {
        if ($shape[0] === '#') {
            // An entry on its own: its rows are its amounts.
            $inCash = $rows->filter(fn ($row) => isset($cash[$row->account_id]));

            return $inCash->isNotEmpty() && $inCash->count() < $rows->count()
                && abs($inCash->sum(fn ($row) => (float) $row->debit - (float) $row->credit)) >= 0.005;
        }

        [$accounts, $zeroSums] = array_pad(explode('|', $shape, 2), 2, '');
        $ids = explode(',', $accounts);

        $mask = 0;
        foreach ($ids as $i => $id) {
            if (isset($cash[(int) $id])) {
                $mask |= 1 << $i;
            }
        }

        return $mask !== 0 && $mask !== (1 << count($ids)) - 1
            && ($zeroSums === '' || ! in_array((string) $mask, explode(',', $zeroSums), true));
    }

    /**
     * What an entry's shape records for its amounts (account_id => ['debit' => x, 'credit' => y]):
     * its accounts and the proper combinations of them that net to zero.
     */
    protected function shapeOf(int $entryId, array $amounts): string
    {
        ksort($amounts);
        if (count($amounts) > self::MAX_ACCOUNTS) {
            return '#'.$entryId;
        }

        $nets = array_map(fn ($a) => (int) round(((float) $a['debit'] - (float) $a['credit']) * 100), array_values($amounts));
        $count = count($nets);
        $zeroSums = [];
        for ($mask = 1; $mask < (1 << $count) - 1; $mask++) {
            $sum = 0;
            foreach ($nets as $i => $net) {
                if ($mask & (1 << $i)) {
                    $sum += $net;
                }
            }
            if ($sum === 0) {
                $zeroSums[] = $mask;
            }
        }

        $shape = implode(',', array_keys($amounts)).($zeroSums ? '|'.implode(',', $zeroSums) : '');

        return strlen($shape) <= self::MAX_SHAPE_LENGTH ? $shape : '#'.$entryId;
    }

    /** @return array<int, array{debit: float, credit: float}> the entry's lines, added up per account */
    protected function amountsByAccount(iterable $lines): array
    {
        $amounts = [];
        foreach ($lines as $line) {
            $line = (array) $line;
            $accountId = (int) $line['account_id'];
            $amounts[$accountId]['debit'] = ($amounts[$accountId]['debit'] ?? 0) + (float) ($line['debit'] ?? 0);
            $amounts[$accountId]['credit'] = ($amounts[$accountId]['credit'] ?? 0) + (float) ($line['credit'] ?? 0);
        }

        return $amounts;
    }

    protected function periodOf(string $date): int
    {
        return (int) str_replace('-', '', substr($date, 0, 7));
    }

    /**
     * Add amounts to one month of a branch, in account order (a stable lock order), in one statement
     * whatever the number of accounts; two postings opening the same month cannot collide.
     */
    protected function write(int $companyId, ?int $branchId, int $period, bool $opening, string $shape, array $amounts): void
    {
        ksort($amounts);
        $now = now();
        $rows = [];
        foreach ($amounts as $accountId => $amount) {
            $rows[] = [
                'company_id' => $companyId, 'branch_id' => $branchId ?: self::NO_BRANCH, 'period' => $period, 'is_opening' => (int) $opening, 'shape' => $shape, 'account_id' => $accountId,
                // Fixed-point text (never scientific notation), as for account_balances.
                'debit' => number_format((float) $amount['debit'], 2, '.', ''),
                'credit' => number_format((float) $amount['credit'], 2, '.', ''),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $incoming = fn (string $column) => DB::getDriverName() === 'sqlite' ? "excluded.{$column}" : "VALUES({$column})";

        DB::table(self::TABLE)->upsert($rows, ['company_id', 'branch_id', 'period', 'is_opening', 'shape', 'account_id'], [
            'debit' => DB::raw('debit + '.$incoming('debit')),
            'credit' => DB::raw('credit + '.$incoming('credit')),
            'updated_at' => $now,
        ]);
    }
}

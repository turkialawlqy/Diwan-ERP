<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Services\ActiveBranch;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ledger based reports. Totals come from the monthly account_balances table
 * for whole months and from ledger lines only for partial edge months, so
 * report cost stays flat as the ledger grows to millions of lines.
 */
class AccountingReports
{
    protected const STATUSES = [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED];

    public function __construct(
        protected TenantManager $tenantManager,
        protected AccountMap $map,
    ) {}

    /** @var array<int, array<int>> every branch id of a company, per company (memo) */
    protected array $companyBranchIds = [];

    /**
     * Branches the reports cover: the one chosen in the top bar (or all the
     * user's branches while "all" is shown). Null: the whole company, which
     * keeps the fast path through account_balances (also when the selection
     * is every branch, so entries without a branch stay in).
     *
     * @return array<int>|null
     */
    protected function branchIds(): ?array
    {
        $ids = app(ActiveBranch::class)->scopeIds();
        if ($ids === null) {
            return null;
        }
        $companyId = $this->companyId();
        $this->companyBranchIds[$companyId] ??= Branch::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_diff($this->companyBranchIds[$companyId], $ids) === [] ? null : $ids;
    }

    protected function companyId(): int
    {
        return (int) $this->tenantManager->getTenantId();
    }

    /**
     * account_id => ['debit' => x, 'credit' => y] for postings dated between
     * $from and $to inclusive (null $from = since the beginning).
     */
    public function totalsBetween(?Carbon $from, Carbon $to, ?array $accountIds = null, ?int $costCenterId = null, bool $excludeClosing = false): array
    {
        $companyId = $this->companyId();
        $totals = [];

        // Profit reports must not see the year-end closing entry (nor its
        // reversal when a year is reopened), or a closed year shows zero.
        if ($excludeClosing) {
            $totals = $this->totalsBetween($from, $to, $accountIds, $costCenterId);
            foreach ($this->closingTotals($from, $to, $accountIds, $costCenterId) as $row) {
                $totals[$row->account_id]['debit'] = ($totals[$row->account_id]['debit'] ?? 0) - (float) $row->debit;
                $totals[$row->account_id]['credit'] = ($totals[$row->account_id]['credit'] ?? 0) - (float) $row->credit;
            }

            return $totals;
        }

        $add = function ($rows) use (&$totals) {
            foreach ($rows as $row) {
                $totals[$row->account_id]['debit'] = ($totals[$row->account_id]['debit'] ?? 0) + (float) $row->debit;
                $totals[$row->account_id]['credit'] = ($totals[$row->account_id]['credit'] ?? 0) + (float) $row->credit;
            }
        };

        // Cost center and branch reports must read lines (balances are not split by either).
        if ($costCenterId || $this->branchIds() !== null) {
            $add($this->lineTotals($from, $to, $accountIds, $costCenterId));

            return $totals;
        }

        // Whole months covered by [from, to] come from account_balances.
        $firstFull = $from ? ($from->day === 1 ? $from->copy() : $from->copy()->addMonthNoOverflow()->startOfMonth()) : null;
        $lastFull = $to->copy()->isLastOfMonth() ? $to->copy()->startOfMonth() : $to->copy()->subMonthNoOverflow()->startOfMonth();

        if (! $firstFull || $firstFull->lte($lastFull)) {
            $add(DB::table('account_balances')
                ->where('company_id', $companyId)
                ->when($firstFull, fn ($q) => $q->where('period', '>=', (int) $firstFull->format('Ym')))
                ->where('period', '<=', (int) $lastFull->format('Ym'))
                ->when($accountIds, fn ($q) => $q->whereIn('account_id', $accountIds))
                ->groupBy('account_id')
                ->selectRaw('account_id, SUM(debit) as debit, SUM(credit) as credit')
                ->get());

            // Partial head month (from is not the 1st).
            if ($from && $from->day !== 1) {
                $add($this->lineTotals($from, $from->copy()->endOfMonth(), $accountIds));
            }
            // Partial tail month (to is not the month end).
            if (! $to->isLastOfMonth()) {
                $add($this->lineTotals($to->copy()->startOfMonth(), $to, $accountIds));
            }
        } else {
            // Range inside a single month: lines only.
            $add($this->lineTotals($from, $to, $accountIds));
        }

        return $totals;
    }

    protected function closingTotals(?Carbon $from, Carbon $to, ?array $accountIds = null, ?int $costCenterId = null): Collection
    {
        $closingIds = DB::table('journal_entries')->where('company_id', $this->companyId())->where('entry_type', 'closing')->pluck('id');
        if ($closingIds->isEmpty()) {
            return collect();
        }

        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->companyId())
            ->whereIn('e.status', self::STATUSES)
            ->when($this->branchIds(), fn ($q, $ids) => $q->whereIn('e.branch_id', $ids))
            ->where(fn ($q) => $q->whereIn('e.id', $closingIds)->orWhereIn('e.reversal_of_id', $closingIds))
            ->when($from, fn ($q) => $q->where('l.date', '>=', $from->toDateString()))
            ->where('l.date', '<=', $to->toDateString().' 23:59:59')
            ->when($accountIds, fn ($q) => $q->whereIn('l.account_id', $accountIds))
            ->when($costCenterId, fn ($q) => $q->where('l.cost_center_id', $costCenterId))
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();
    }

    protected function lineTotals(?Carbon $from, Carbon $to, ?array $accountIds = null, ?int $costCenterId = null): Collection
    {
        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->companyId())
            ->whereIn('e.status', self::STATUSES)
            ->when($this->branchIds(), fn ($q, $ids) => $q->whereIn('e.branch_id', $ids))
            ->when($from, fn ($q) => $q->where('l.date', '>=', $from->toDateString()))
            ->where('l.date', '<=', $to->toDateString().' 23:59:59')
            ->when($accountIds, fn ($q) => $q->whereIn('l.account_id', $accountIds))
            ->when($costCenterId, fn ($q) => $q->where('l.cost_center_id', $costCenterId))
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();
    }

    /**
     * Trial balance with opening, period movement and closing columns, rolled
     * up the account tree.
     */
    public function trialBalance(Carbon $from, Carbon $to, bool $hideZero = true, ?int $maxLevel = null, ?int $costCenterId = null): array
    {
        $accounts = $this->accounts();
        $opening = $costCenterId ? [] : $this->totalsBetween(null, $from->copy()->subDay());
        $movement = $this->totalsBetween($from, $to, null, $costCenterId);

        $rows = [];
        foreach ($accounts as $account) {
            $o = $opening[$account->id] ?? ['debit' => 0, 'credit' => 0];
            $m = $movement[$account->id] ?? ['debit' => 0, 'credit' => 0];
            $rows[$account->id] = [
                'id' => $account->id,
                'parent_id' => $account->parent_id,
                'code' => $account->code,
                'name' => $account->name_ar,
                'type' => $account->type,
                'is_parent' => (bool) $account->is_parent,
                'opening_debit' => (float) $o['debit'],
                'opening_credit' => (float) $o['credit'],
                'debit' => (float) $m['debit'],
                'credit' => (float) $m['credit'],
            ];
        }

        $rows = $this->rollUp($rows);

        $result = [];
        $totals = ['opening_debit' => 0, 'opening_credit' => 0, 'debit' => 0, 'credit' => 0, 'closing_debit' => 0, 'closing_credit' => 0];

        foreach ($rows as $row) {
            $openingNet = $row['opening_debit'] - $row['opening_credit'];
            $closingNet = $openingNet + $row['debit'] - $row['credit'];
            $row['opening_debit'] = $openingNet > 0 ? round($openingNet, 2) : 0;
            $row['opening_credit'] = $openingNet < 0 ? round(-$openingNet, 2) : 0;
            $row['closing_debit'] = $closingNet > 0 ? round($closingNet, 2) : 0;
            $row['closing_credit'] = $closingNet < 0 ? round(-$closingNet, 2) : 0;
            $row['debit'] = round($row['debit'], 2);
            $row['credit'] = round($row['credit'], 2);

            if ($hideZero && ! $row['opening_debit'] && ! $row['opening_credit'] && ! $row['debit'] && ! $row['credit']) {
                continue;
            }
            if ($maxLevel && $row['level'] > $maxLevel) {
                continue;
            }

            if (! $row['is_parent']) {
                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $row[$key];
                }
            }

            $result[] = $row;
        }

        return ['rows' => $result, 'totals' => array_map(fn ($v) => round($v, 2), $totals)];
    }

    /**
     * Account statement / general ledger for one account (paginated lines with
     * running balance and an opening balance row).
     */
    public function ledger(Account $account, Carbon $from, Carbon $to, int $perPage = 50, ?string $partyType = null, ?int $partyId = null, ?int $costCenterId = null)
    {
        $openingTotals = $partyType || $costCenterId
            ? $this->partyOpening($account->id, $from, $partyType, $partyId, $costCenterId)
            : ($this->totalsBetween(null, $from->copy()->subDay(), [$account->id])[$account->id] ?? ['debit' => 0, 'credit' => 0]);

        $opening = $account->signedBalance((float) $openingTotals['debit'], (float) $openingTotals['credit']);

        $base = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->companyId())
            ->where('l.account_id', $account->id)
            ->whereIn('e.status', self::STATUSES)
            ->when($this->branchIds(), fn ($q, $ids) => $q->whereIn('e.branch_id', $ids))
            ->whereBetween('l.date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->when($partyType, fn ($q) => $q->where('l.party_type', $partyType)->where('l.party_id', $partyId))
            ->when($costCenterId, fn ($q) => $q->where('l.cost_center_id', $costCenterId));

        $periodTotals = (clone $base)->selectRaw('COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')->first();

        $page = (clone $base)
            ->orderBy('l.date')
            ->orderBy('l.id')
            ->select('l.id', 'l.date', 'l.debit', 'l.credit', 'l.description', 'l.journal_entry_id', 'e.entry_number', 'e.reference', 'e.description as entry_description', 'e.source_type', 'e.source_id')
            ->paginate($perPage)
            ->withQueryString();

        // Running balance must include all lines before the current page.
        $before = 0.0;
        if ($page->currentPage() > 1 && $page->count()) {
            $first = $page->first();
            $prior = (clone $base)
                ->where(fn ($q) => $q->where('l.date', '<', $first->date)->orWhere(fn ($q) => $q->where('l.date', $first->date)->where('l.id', '<', $first->id)))
                ->selectRaw('COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')
                ->first();
            $before = $account->signedBalance((float) $prior->debit, (float) $prior->credit);
        }

        $running = $opening + $before;
        $page->getCollection()->transform(function ($line) use (&$running, $account) {
            $running += $account->signedBalance((float) $line->debit, (float) $line->credit);
            $line->balance = round($running, 2);
            $line->debit = (float) $line->debit;
            $line->credit = (float) $line->credit;
            $line->date = substr((string) $line->date, 0, 10);

            return $line;
        });

        return [
            'opening_balance' => round($opening, 2),
            'period_debit' => round((float) $periodTotals->debit, 2),
            'period_credit' => round((float) $periodTotals->credit, 2),
            'closing_balance' => round($opening + $account->signedBalance((float) $periodTotals->debit, (float) $periodTotals->credit), 2),
            'lines' => $page,
        ];
    }

    protected function partyOpening(int $accountId, Carbon $from, ?string $partyType, ?int $partyId, ?int $costCenterId): array
    {
        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->companyId())
            ->where('l.account_id', $accountId)
            ->whereIn('e.status', self::STATUSES)
            ->when($this->branchIds(), fn ($q, $ids) => $q->whereIn('e.branch_id', $ids))
            ->where('l.date', '<', $from->toDateString())
            ->when($partyType, fn ($q) => $q->where('l.party_type', $partyType)->where('l.party_id', $partyId))
            ->when($costCenterId, fn ($q) => $q->where('l.cost_center_id', $costCenterId))
            ->selectRaw('COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')
            ->first();

        return ['debit' => (float) $row->debit, 'credit' => (float) $row->credit];
    }

    /**
     * Income statement for a period (optionally per cost center).
     */
    public function incomeStatement(Carbon $from, Carbon $to, ?int $costCenterId = null): array
    {
        $movement = $this->totalsBetween($from, $to, null, $costCenterId, true);
        $sections = $this->sections(['revenue', 'expense'], $movement);

        $revenue = $sections['revenue']['total'];
        $expense = $sections['expense']['total'];
        // Cost of sales = every account of the cost-of-sales group (COGS,
        // non-stock purchases, adjustments, project and subcontract costs).
        $cogsIds = $this->groupLeafIds(array_filter([$this->map->find('cogs'), $this->map->find('purchases_cogs'), $this->map->find('inventory_adjustment'),
            $this->map->find('project_costs'), $this->map->find('subcontractor_costs')]));
        $cogs = collect($sections['expense']['rows'])->whereIn('id', $cogsIds)->sum('amount');
        // Gross profit compares operating revenue only (no other income or asset gains).
        $nonOperating = array_filter([$this->map->find('other_revenue'), $this->map->find('asset_disposal_gain')]);
        $operatingRevenue = $revenue - collect($sections['revenue']['rows'])->whereIn('id', $nonOperating)->sum('amount');

        return [
            'revenue' => $sections['revenue'],
            'expense' => $sections['expense'],
            'total_revenue' => round($revenue, 2),
            'operating_revenue' => round($operatingRevenue, 2),
            'cost_of_sales' => round($cogs, 2),
            'gross_profit' => round($operatingRevenue - $cogs, 2),
            'total_expense' => round($expense, 2),
            'net_profit' => round($revenue - $expense, 2),
        ];
    }

    /** Leaf accounts under the parents of the given accounts (the cost-of-sales group). */
    protected function groupLeafIds(array $accountIds): array
    {
        $accounts = Account::query()->get(['id', 'parent_id', 'is_parent']);
        $parents = $accounts->whereIn('id', $accountIds)->pluck('parent_id')->filter()->unique()->all();
        $ids = $accountIds;
        $frontier = $parents;
        while ($frontier) {
            $children = $accounts->whereIn('parent_id', $frontier);
            $ids = array_merge($ids, $children->where('is_parent', false)->pluck('id')->all());
            $frontier = $children->where('is_parent', true)->pluck('id')->all();
        }

        return array_values(array_unique($ids));
    }

    /**
     * Balance sheet as of a date. Unclosed profit (revenue - expense not yet
     * transferred by year end closing) is shown inside equity.
     */
    public function balanceSheet(Carbon $asOf): array
    {
        $cumulative = $this->totalsBetween(null, $asOf);
        $sections = $this->sections(['asset', 'liability', 'equity'], $cumulative);
        $pl = $this->sections(['revenue', 'expense'], $cumulative);
        $unclosedProfit = round($pl['revenue']['total'] - $pl['expense']['total'], 2);

        $totalAssets = $sections['asset']['total'];
        $totalLiabilities = $sections['liability']['total'];
        $totalEquity = round($sections['equity']['total'] + $unclosedProfit, 2);

        return [
            'assets' => $sections['asset'],
            'liabilities' => $sections['liability'],
            'equity' => $sections['equity'],
            'unclosed_profit' => $unclosedProfit,
            'total_assets' => round($totalAssets, 2),
            'total_liabilities' => round($totalLiabilities, 2),
            'total_equity' => $totalEquity,
            'total_liabilities_equity' => round($totalLiabilities + $totalEquity, 2),
            'is_balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
        ];
    }

    /**
     * Cash flow statement (direct method): every movement of cash / bank
     * accounts is attributed to the cash flow category of the counterpart
     * accounts of the same entry.
     */
    public function cashFlow(Carbon $from, Carbon $to): array
    {
        $cashIds = $this->cashAccountIds();
        $categories = Account::query()->pluck('cash_flow_category', 'id');
        $names = Account::query()->pluck('name_ar', 'id');

        $flows = ['operating' => [], 'investing' => [], 'financing' => []];
        $openingInRange = 0.0;
        $range = [$from->toDateString(), $to->toDateString().' 23:59:59'];

        // Entries that move cash: a cash line, another line and a net cash
        // movement (transfers between cash accounts net to zero). Whole entries
        // are summed in SQL: paging their lines could split an entry.
        $cash = $cashIds ?: [0];
        $isCash = 'l.account_id IN ('.implode(', ', array_fill(0, count($cash), '?')).')';
        $entries = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->companyId())
            ->whereIn('e.status', self::STATUSES)
            ->when($this->branchIds(), fn ($q, $ids) => $q->whereIn('e.branch_id', $ids))
            ->whereBetween('l.date', $range)
            ->groupBy('l.journal_entry_id')
            ->havingRaw("SUM(CASE WHEN {$isCash} THEN 1 ELSE 0 END) > 0", $cash)
            ->havingRaw("SUM(CASE WHEN {$isCash} THEN 0 ELSE 1 END) > 0", $cash)
            ->havingRaw("ABS(SUM(CASE WHEN {$isCash} THEN l.debit - l.credit ELSE 0 END)) >= 0.005", $cash)
            ->selectRaw("l.journal_entry_id, MAX(CASE WHEN e.entry_type = 'opening' THEN 1 ELSE 0 END) as is_opening");

        $totals = DB::table('journal_entry_lines as l')
            ->joinSub($entries, 'f', 'f.journal_entry_id', '=', 'l.journal_entry_id')
            ->whereBetween('l.date', $range)
            ->groupBy('f.is_opening', 'l.account_id')
            ->selectRaw('f.is_opening, l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();

        foreach ($totals as $line) {
            $cashLine = in_array((int) $line->account_id, $cashIds, true);
            if ($line->is_opening) {
                // Opening balances are not a cash flow of the period.
                if ($cashLine) {
                    $openingInRange += (float) $line->debit - (float) $line->credit;
                }
            } elseif (! $cashLine) {
                // In a balanced entry the cash movement equals the other lines'
                // credits minus debits, so each account's signed amount is
                // exactly its share of the flow.
                $category = in_array($categories[$line->account_id] ?? null, ['operating', 'investing', 'financing'], true) ? $categories[$line->account_id] : 'operating';
                $flows[$category][$line->account_id] = (float) $line->credit - (float) $line->debit;
            }
        }

        $result = [];
        $net = 0.0;
        foreach ($flows as $category => $accounts) {
            $rows = collect($accounts)->map(fn ($amount, $id) => ['account_id' => $id, 'name' => $names[$id] ?? '', 'amount' => round($amount, 2)])
                ->filter(fn ($r) => abs($r['amount']) >= 0.01)->sortBy('name')->values();
            $total = round($rows->sum('amount'), 2);
            $net += $total;
            $result[$category] = ['rows' => $rows, 'total' => $total];
        }

        $openingCash = collect($this->totalsBetween(null, $from->copy()->subDay(), $cashIds ?: [0]))->sum(fn ($t) => $t['debit'] - $t['credit']) + $openingInRange;

        return [
            'sections' => $result,
            'net_change' => round($net, 2),
            'opening_cash' => round($openingCash, 2),
            'closing_cash' => round($openingCash + $net, 2),
        ];
    }

    /**
     * Cash flow statement, indirect method: operating cash is derived from
     * net profit, adjusted for non-cash items (depreciation, impairment,
     * provisions, gains on investing items) and for the change in working
     * capital. Investing and financing are the actual cash flows of the
     * direct method, so both statements reach the same cash; any operating
     * difference (non-cash investing or financing transactions, such as an
     * asset bought on credit) is shown on its own line.
     */
    public function cashFlowIndirect(Carbon $from, Carbon $to): array
    {
        $direct = $this->cashFlow($from, $to);
        $cashIds = $this->cashAccountIds();
        $accounts = Account::query()->where('is_parent', false)->get(['id', 'code', 'name_ar', 'type', 'cash_flow_category'])->keyBy('id');

        // Period movement (credit - debit) per account, without opening and year-end closing entries.
        $closingIds = DB::table('journal_entries')->where('company_id', $this->companyId())->where('entry_type', 'closing')->pluck('id');
        $movement = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->companyId())
            ->whereIn('e.status', self::STATUSES)
            ->when($this->branchIds(), fn ($q, $ids) => $q->whereIn('e.branch_id', $ids))
            ->whereBetween('l.date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->where(fn ($q) => $q->whereNull('e.entry_type')->orWhereNotIn('e.entry_type', ['opening', 'closing']))
            ->when($closingIds->isNotEmpty(), fn ($q) => $q->where(fn ($q) => $q->whereNull('e.reversal_of_id')->orWhereNotIn('e.reversal_of_id', $closingIds)))
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.credit) - SUM(l.debit) as net')
            ->pluck('net', 'account_id')->map(fn ($v) => (float) $v);
        $net = fn ($ids) => round(collect((array) $ids)->filter()->sum(fn ($id) => $movement[$id] ?? 0), 2);
        $row = fn ($id, $amount) => ['id' => $id, 'code' => $accounts[$id]->code ?? '', 'name' => $accounts[$id]->name_ar ?? '', 'amount' => round($amount, 2)];

        $profitAndLoss = $accounts->whereIn('type', ['revenue', 'expense']);
        $profit = $net($profitAndLoss->keys()->all());

        $categories = AssetCategory::query()->get(['asset_account_id', 'accumulated_account_id', 'expense_account_id']);
        $depreciationIds = $categories->pluck('expense_account_id')->push($this->map->find('depreciation_expense'))->filter()->unique()->all();
        $impairmentId = $this->map->find('impairment_loss');
        $provisionIds = array_values(array_filter([$this->map->find('end_of_service_provision'), $this->map->find('leave_provision')]));
        $fixedAssetIds = $categories->pluck('asset_account_id')->merge($categories->pluck('accumulated_account_id'))
            ->push($this->map->find('fixed_assets'))->push($this->map->find('accumulated_depreciation'))->filter()->unique()->all();

        $adjustments = [];
        if ($amount = -$net($depreciationIds)) {
            $adjustments[] = ['id' => null, 'name' => 'الإهلاك', 'amount' => $amount];
        }
        if ($impairmentId && ($amount = -$net([$impairmentId]))) {
            $adjustments[] = ['id' => null, 'name' => 'خسائر هبوط قيمة الأصول', 'amount' => $amount];
        }
        foreach ($provisionIds as $id) {
            if ($amount = $net([$id])) {
                $adjustments[] = $row($id, $amount);
            }
        }
        // Gains and losses on investing / financing items: their cash is in those sections.
        foreach ($profitAndLoss->whereIn('cash_flow_category', ['investing', 'financing']) as $id => $account) {
            if (! in_array($id, $depreciationIds, true) && $id !== $impairmentId && ($amount = -$net([$id]))) {
                $adjustments[] = $row($id, $amount);
            }
        }

        // Working capital: operating balance sheet accounts other than cash, fixed assets and provisions.
        $excluded = array_merge($cashIds, $fixedAssetIds, $provisionIds);
        $workingCapital = $accounts->whereIn('type', ['asset', 'liability'])
            ->reject(fn ($a) => in_array($a->id, $excluded, true) || in_array($a->cash_flow_category, ['investing', 'financing', 'cash'], true))
            ->map(fn ($a) => $row($a->id, $net([$a->id])))
            ->filter(fn ($r) => abs($r['amount']) >= 0.01)->sortBy('code')->values()->all();

        $operating = $direct['sections']['operating']['total'];
        $explained = round($profit + array_sum(array_column($adjustments, 'amount')) + array_sum(array_column($workingCapital, 'amount')), 2);

        return $direct + [
            'indirect' => [
                'net_profit' => $profit,
                'adjustments' => $adjustments,
                'working_capital' => $workingCapital,
                'non_cash' => round($operating - $explained, 2),
                'operating_total' => $operating,
            ],
        ];
    }

    /**
     * Receivable / payable aging from open documents.
     */
    /**
     * Aging as of a date, equal to the ledger: each party's balance on the
     * receivables / payables account at that date (journal lines carry the
     * party) is spread over its most recent invoices (older ones are taken as
     * paid first) and aged by their due date. A balance larger than the
     * invoices (opening balances) is shown as over 90 days, a credit balance
     * (advance) as current; lines without a party form their own row.
     */
    public function aging(string $kind, Carbon $asOf): array
    {
        $payables = $kind === 'payables';
        [$role, $partyType, $docTable, $partyColumn, $partyModel] = $payables
            ? ['payables', 'supplier', 'purchase_invoices', 'supplier_id', Supplier::class]
            : ['receivables', 'customer', 'invoices', 'customer_id', Customer::class];
        $accountIds = $payables ? [$this->map->id($role)] : (app(PartyAccounts::class)->receivableAccountIds() ?: [$this->map->id($role)]);
        $sign = $payables ? -1 : 1;

        $balances = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', $this->companyId())
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->when($this->branchIds(), fn ($q, $ids) => $q->whereIn('e.branch_id', $ids))
            ->where('e.date', '<=', $asOf->toDateString().' 23:59:59')
            ->whereIn('l.account_id', $accountIds)
            ->groupBy('l.party_type', 'l.party_id')
            ->selectRaw('l.party_type, l.party_id, SUM(l.debit - l.credit) as balance')
            ->get();

        $names = $partyModel::whereIn('id', $balances->where('party_type', $partyType)->pluck('party_id')->filter())->pluck('name', 'id');
        $empty = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90plus' => 0.0];
        $rows = collect();

        foreach ($balances as $b) {
            $balance = round($sign * (float) $b->balance, 2);
            if (abs($balance) < 0.005) {
                continue;
            }
            $buckets = $empty;
            $isParty = $b->party_type === $partyType && $b->party_id;

            if ($balance < 0 || ! $isParty) {
                $buckets[$balance < 0 ? 'current' : 'd90plus'] = $balance;
            } else {
                $left = $balance;
                $documents = DB::table($docTable)->where('company_id', $this->companyId())->where($partyColumn, $b->party_id)
                    ->where('invoice_date', '<=', $asOf->toDateString().' 23:59:59')
                    ->orderByDesc('invoice_date')->orderByDesc('id')->get(['total', 'exchange_rate', 'invoice_date', 'due_date']);
                foreach ($documents as $doc) {
                    if ($left <= 0.004) {
                        break;
                    }
                    $open = min($left, round((float) $doc->total * (float) ($doc->exchange_rate ?: 1), 2));
                    $days = (int) Carbon::parse($doc->due_date ?: $doc->invoice_date)->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false);
                    $buckets[match (true) {
                        $days <= 0 => 'current', $days <= 30 => 'd30', $days <= 60 => 'd60', $days <= 90 => 'd90', default => 'd90plus'
                    }] += $open;
                    $left = round($left - $open, 2);
                }
                $buckets['d90plus'] += max(0, $left);
            }

            $rows->push(array_merge(
                ['id' => $isParty ? (int) $b->party_id : null, 'name' => $isParty ? ($names[$b->party_id] ?? '#'.$b->party_id) : 'قيود بدون تحديد طرف'],
                array_map(fn ($v) => round($v, 2), $buckets),
                ['total' => $balance],
            ));
        }

        $rows = $rows->sortByDesc('total')->values();
        $totals = [];
        foreach (['current', 'd30', 'd60', 'd90', 'd90plus', 'total'] as $key) {
            $totals[$key] = round($rows->sum($key), 2);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** Zero-rated supplies that are exports (ZATCA exemption codes). */
    protected const EXPORT_CODES = ['VATEX-SA-32', 'VATEX-SA-33', 'VATEX-SA-34-1', 'VATEX-SA-34-2', 'VATEX-SA-34-3', 'VATEX-SA-34-4', 'VATEX-SA-34-5'];

    /**
     * Tax summary per branch and currency, for branches in different
     * countries: sales and purchases by rate with the tax on each, in the
     * documents' own currency (the currency the return is filed in), and the
     * net payable. Saudi branches also have the ZATCA return above.
     *
     * @return array<int, array{branch_id: ?int, branch: string, country: string, country_name: string, tax_name: string, currency: string, sales: array, purchases: array, output: float, input: float, net: float}>
     */
    public function taxByBranch(Carbon $from, Carbon $to): array
    {
        $companyId = $this->companyId();
        $base = strtoupper((string) (Company::find($companyId)?->currency ?: 'SAR'));
        $range = [$from->toDateString(), $to->toDateString().' 23:59:59'];
        $groups = [];
        $add = function ($branchId, $currency, string $side, float $rate, float $amount, float $tax) use (&$groups, $base) {
            $key = ($branchId ?: 0).'|'.strtoupper((string) ($currency ?: $base));
            $groups[$key][$side][(string) round($rate, 2)] ??= ['rate' => round($rate, 2), 'amount' => 0.0, 'tax' => 0.0];
            $groups[$key][$side][(string) round($rate, 2)]['amount'] += $amount;
            $groups[$key][$side][(string) round($rate, 2)]['tax'] += $tax;
        };

        DB::table('invoice_items as i')->join('invoices as v', 'v.id', '=', 'i.invoice_id')->where('v.company_id', $companyId)->whereBetween('v.invoice_date', $range)
            ->selectRaw('v.branch_id, v.currency_code, i.tax_rate, SUM(COALESCE(NULLIF(i.subtotal, 0), i.quantity * i.unit_price - i.discount)) as amount, SUM(i.tax_amount) as tax')
            ->groupBy('v.branch_id', 'v.currency_code', 'i.tax_rate')->get()
            ->each(fn ($r) => $add($r->branch_id, $r->currency_code, 'sales', (float) $r->tax_rate, (float) $r->amount, (float) $r->tax));
        // Credit and debit notes belong to the branch of the invoice they correct.
        DB::table('credit_notes as n')->leftJoin('invoices as v', 'v.id', '=', 'n.invoice_id')->where('n.company_id', $companyId)
            ->whereIn('n.status', ['approved', 'refunded'])->whereBetween('n.credit_note_date', $range)->get(['n.subtotal', 'n.tax_amount', 'n.currency_code', 'v.branch_id'])
            ->each(fn ($n) => $add($n->branch_id, $n->currency_code, 'sales', (float) $n->subtotal > 0 ? round((float) $n->tax_amount / (float) $n->subtotal * 100, 2) : 0, -(float) $n->subtotal, -(float) $n->tax_amount));
        DB::table('debit_notes as n')->leftJoin('invoices as v', 'v.id', '=', 'n.invoice_id')->where('n.company_id', $companyId)
            ->whereNotIn('n.status', ['draft', 'cancelled'])->whereBetween('n.debit_note_date', $range)->get(['n.subtotal', 'n.tax_amount', 'n.tax_rate', 'n.currency_code', 'v.branch_id'])
            ->each(fn ($n) => $add($n->branch_id, $n->currency_code, 'sales', (float) $n->tax_rate, (float) $n->subtotal, (float) $n->tax_amount));
        DB::table('purchase_invoice_items as i')->join('purchase_invoices as p', 'p.id', '=', 'i.purchase_invoice_id')->where('p.company_id', $companyId)->whereBetween('p.invoice_date', $range)
            ->selectRaw('p.branch_id, p.currency_code, i.tax_rate, SUM(i.quantity * i.unit_price - i.discount) as amount, SUM(i.tax_amount) as tax')
            ->groupBy('p.branch_id', 'p.currency_code', 'i.tax_rate')->get()
            ->each(fn ($r) => $add($r->branch_id, $r->currency_code, 'purchases', (float) $r->tax > 0 ? (float) $r->tax_rate : 0, (float) $r->amount, (float) $r->tax));

        $branches = Branch::query()->get()->keyBy('id');
        $out = [];
        foreach ($groups as $key => $sides) {
            [$branchId, $currency] = explode('|', $key);
            $branch = $branches->get((int) $branchId);
            $profile = TaxProfile::forBranch($branch, Company::find($companyId));
            $round = fn (array $rows) => array_values(array_map(fn ($r) => ['rate' => $r['rate'], 'amount' => round($r['amount'], 2), 'tax' => round($r['tax'], 2)], $rows));
            $output = round(array_sum(array_column($sides['sales'] ?? [], 'tax')), 2);
            $input = round(array_sum(array_column($sides['purchases'] ?? [], 'tax')), 2);
            $out[] = [
                'branch_id' => $branch?->id, 'branch' => $branch?->name_ar ?? 'بدون فرع', 'country' => $profile->countryCode, 'country_name' => $profile->country['name'],
                'tax_name' => $profile->taxName(), 'currency' => $currency,
                'sales' => $round($sides['sales'] ?? []), 'purchases' => $round($sides['purchases'] ?? []),
                'output' => $output, 'input' => $input, 'net' => round($output - $input, 2),
            ];
        }
        usort($out, fn ($a, $b) => [$a['country'], $a['branch']] <=> [$b['country'], $b['branch']]);

        return $out;
    }

    /**
     * VAT return in the ZATCA return layout, from documents, cross-checked
     * against the VAT ledger accounts. Sales are classified line by line
     * (standard / zero-rated / export / exempt); credit notes and debit notes
     * are adjustments of the box of the lines they correct, purchase returns
     * adjust the purchases. Foreign-currency documents are converted at their
     * own rate: the return is filed in SAR.
     */
    public function vatReturn(Carbon $from, Carbon $to): array
    {
        $companyId = $this->companyId();
        $range = [$from->toDateString(), $to->toDateString().' 23:59:59'];
        // Branches in other countries file their own returns (see taxByBranch()): left out of the ZATCA form.
        $company = Company::find($companyId);
        $foreign = Branch::query()->get()->filter(fn ($b) => TaxProfile::forBranch($b, $company)->countryCode !== 'SA')->pluck('id')->all();
        $saudiOnly = fn ($q, string $column) => $foreign ? $q->where(fn ($w) => $w->whereNull($column)->orWhereNotIn($column, $foreign)) : $q;
        $foreignInvoices = $foreign ? DB::table('invoices')->whereIn('branch_id', $foreign)->pluck('id')->all() : [];
        $boxes = array_fill_keys([1, 3, 4, 5, 7, 10], ['amount' => 0.0, 'adjustment' => 0.0, 'vat' => 0.0]);
        $boxOf = function (?string $category, ?string $code, float $vat): ?int {
            if ($vat > 0.0001 || ($category ?? 'S') === 'S') {
                return $vat > 0.0001 ? 1 : 3; // legacy lines without a category and no VAT are zero-rated
            }

            return match ($category) {
                'Z' => in_array($code, self::EXPORT_CODES, true) ? 4 : 3,
                'E' => 5,
                default => null, // O: out of scope, not reported
            };
        };
        $add = function (?int $box, string $column, float $amount, float $vat) use (&$boxes) {
            if ($box) {
                $boxes[$box][$column] += $amount;
                $boxes[$box]['vat'] += $vat;
            }
        };

        // Sales, line by line (POS lines saved before the fix have no subtotal).
        DB::table('invoice_items as i')->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->where('v.company_id', $companyId)->whereBetween('v.invoice_date', $range)->tap(fn ($q) => $saudiOnly($q, 'v.branch_id'))
            ->selectRaw('i.vat_category as cat, i.vat_exemption_code as code,
                SUM(COALESCE(NULLIF(i.subtotal, 0), i.quantity * i.unit_price - i.discount) * v.exchange_rate) as amount,
                SUM(i.tax_amount * v.exchange_rate) as vat, SUM(i.tax_amount) as raw_vat')
            ->groupBy('i.vat_category', 'i.vat_exemption_code', DB::raw('CASE WHEN i.tax_amount > 0 THEN 1 ELSE 0 END'))
            ->get()->each(fn ($r) => $add($boxOf($r->cat, $r->code, (float) $r->raw_vat), 'amount', (float) $r->amount, (float) $r->vat));

        // Other revenue vouchers with VAT are standard-rated sales.
        $otherRevenue = DB::table('revenues')->where('company_id', $companyId)->whereBetween('revenue_date', $range)->where('tax_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(amount),0) as amount, COALESCE(SUM(tax_amount),0) as vat')->first();
        $add(1, 'amount', (float) $otherRevenue->amount, (float) $otherRevenue->vat);

        // Taxable sales of fixed assets.
        $assetSales = DB::table('fixed_assets')->where('company_id', $companyId)->whereBetween('disposal_date', $range)->where('disposal_vat', '>', 0)
            ->selectRaw('COALESCE(SUM(disposal_amount),0) as amount, COALESCE(SUM(disposal_vat),0) as vat')->first();
        $add(1, 'amount', (float) $assetSales->amount, (float) $assetSales->vat);

        // Credit notes reduce the box of the lines they credit.
        $notes = DB::table('credit_notes')->where('company_id', $companyId)->whereIn('status', ['approved', 'refunded'])->whereBetween('credit_note_date', $range)
            ->when($foreignInvoices, fn ($q) => $q->where(fn ($w) => $w->whereNull('invoice_id')->orWhereNotIn('invoice_id', $foreignInvoices)))->get();
        $noteLines = DB::table('credit_note_items')->whereIn('credit_note_id', $notes->pluck('id'))->get()->groupBy('credit_note_id');
        foreach ($notes as $note) {
            $rate = (float) ($note->exchange_rate ?: 1);
            $lines = $noteLines->get($note->id);
            if ($lines && $lines->isNotEmpty()) {
                foreach ($lines as $l) {
                    $add($boxOf($l->vat_category, $l->vat_exemption_code, (float) $l->tax_amount), 'adjustment', -((float) $l->total - (float) $l->tax_amount) * $rate, -(float) $l->tax_amount * $rate);
                }
            } else {
                $add($boxOf(null, null, (float) $note->tax_amount), 'adjustment', -(float) $note->subtotal * $rate, -(float) $note->tax_amount * $rate);
            }
        }

        // Debit notes increase the supplies they correct.
        foreach (DB::table('debit_notes')->where('company_id', $companyId)->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('debit_note_date', $range)
            ->when($foreignInvoices, fn ($q) => $q->where(fn ($w) => $w->whereNull('invoice_id')->orWhereNotIn('invoice_id', $foreignInvoices)))->get() as $note) {
            $rate = (float) ($note->exchange_rate ?: 1);
            $add($boxOf(null, null, (float) $note->tax_amount), 'adjustment', (float) $note->subtotal * $rate, (float) $note->tax_amount * $rate);
        }

        // Purchases (taxed lines → box 7, untaxed → box 10), expenses with VAT, minus purchase returns.
        DB::table('purchase_invoice_items as i')->join('purchase_invoices as p', 'p.id', '=', 'i.purchase_invoice_id')
            ->where('p.company_id', $companyId)->whereBetween('p.invoice_date', $range)->tap(fn ($q) => $saudiOnly($q, 'p.branch_id'))
            ->selectRaw('CASE WHEN i.tax_amount > 0 THEN 7 ELSE 10 END as box,
                SUM((i.quantity * i.unit_price - i.discount) * p.exchange_rate) as amount, SUM(i.tax_amount * p.exchange_rate) as vat')
            ->groupBy(DB::raw('CASE WHEN i.tax_amount > 0 THEN 7 ELSE 10 END'))
            ->get()->each(fn ($r) => $add((int) $r->box, 'amount', (float) $r->amount, (float) $r->vat));

        $expenses = DB::table('expenses')->where('company_id', $companyId)->whereBetween('expense_date', $range)->where('tax_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(amount),0) as amount, COALESCE(SUM(tax_amount),0) as vat')->first();
        $add(7, 'amount', (float) $expenses->amount, (float) $expenses->vat);

        $assets = DB::table('fixed_assets')->where('company_id', $companyId)->whereBetween('acquisition_date', $range)->where('acquisition_vat', '>', 0)
            ->selectRaw('COALESCE(SUM(cost),0) as amount, COALESCE(SUM(acquisition_vat),0) as vat')->first();
        $add(7, 'amount', (float) $assets->amount, (float) $assets->vat);

        DB::table('purchase_returns as r')->leftJoin('purchase_invoices as p', 'p.id', '=', 'r.purchase_invoice_id')
            ->where('r.company_id', $companyId)->whereNotIn('r.status', ['draft', 'cancelled'])->whereBetween('r.return_date', $range)
            ->get(['r.subtotal', 'r.tax_amount', 'p.exchange_rate'])
            ->each(fn ($r) => $add((float) $r->tax_amount > 0 ? 7 : 10, 'adjustment', -(float) $r->subtotal * (float) ($r->exchange_rate ?: 1), -(float) $r->tax_amount * (float) ($r->exchange_rate ?: 1)));

        $row = fn (int $no, string $label) => ['no' => $no, 'label' => $label, 'amount' => round($boxes[$no]['amount'], 2), 'adjustment' => round($boxes[$no]['adjustment'], 2), 'vat' => round($boxes[$no]['vat'], 2)];
        $sum = fn (array $nos, string $key) => round(array_sum(array_map(fn ($n) => $boxes[$n][$key], $nos)), 2);
        $outputVat = round($boxes[1]['vat'], 2);
        $inputVat = round($boxes[7]['vat'], 2);

        $ledger = $this->totalsBetween($from, $to, array_filter([$this->map->find('vat_output'), $this->map->find('vat_input')]));
        $ledgerOutput = ($ledger[$this->map->find('vat_output')]['credit'] ?? 0) - ($ledger[$this->map->find('vat_output')]['debit'] ?? 0);
        $ledgerInput = ($ledger[$this->map->find('vat_input')]['debit'] ?? 0) - ($ledger[$this->map->find('vat_input')]['credit'] ?? 0);

        return [
            'boxes' => [
                $row(1, 'المبيعات الخاضعة للنسبة الأساسية'),
                $row(3, 'المبيعات المحلية الخاضعة للنسبة الصفرية'),
                $row(4, 'الصادرات'),
                $row(5, 'المبيعات المعفاة'),
                ['no' => 6, 'label' => 'إجمالي المبيعات', 'amount' => $sum([1, 3, 4, 5], 'amount'), 'adjustment' => $sum([1, 3, 4, 5], 'adjustment'), 'vat' => $outputVat],
                $row(7, 'المشتريات الخاضعة للنسبة الأساسية'),
                $row(10, 'المشتريات الخاضعة للنسبة الصفرية / المعفاة'),
                ['no' => 12, 'label' => 'إجمالي المشتريات', 'amount' => $sum([7, 10], 'amount'), 'adjustment' => $sum([7, 10], 'adjustment'), 'vat' => $inputVat],
            ],
            'output_vat' => $outputVat,
            'input_vat' => $inputVat,
            'net_vat' => round($outputVat - $inputVat, 2),
            'ledger_output_vat' => round($ledgerOutput, 2),
            'ledger_input_vat' => round($ledgerInput, 2),
            'ledger_matches' => abs($ledgerOutput - $outputVat) < 0.05 && abs($ledgerInput - $inputVat) < 0.05,
        ];
    }

    /**
     * Ids of cash and bank ledger accounts.
     */
    public function cashAccountIds(): array
    {
        $ids = array_merge(
            BankAccount::query()->whereNotNull('account_id')->pluck('account_id')->all(),
            Account::query()->where('cash_flow_category', 'cash')->where('is_parent', false)->pluck('id')->all(),
        );
        foreach (['default_cash', 'default_bank', 'petty_cash'] as $role) {
            if ($id = $this->map->find($role)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    protected function accounts(): Collection
    {
        return Account::query()->orderBy('code')->get(['id', 'parent_id', 'code', 'name_ar', 'type', 'nature', 'is_parent']);
    }

    /**
     * Roll leaf totals up to their parents and attach the tree level.
     */
    protected function rollUp(array $rows): array
    {
        $levels = [];
        $levelOf = function ($id) use (&$levelOf, &$levels, $rows) {
            if (isset($levels[$id])) {
                return $levels[$id];
            }
            $parent = $rows[$id]['parent_id'] ?? null;

            return $levels[$id] = $parent && isset($rows[$parent]) ? $levelOf($parent) + 1 : 1;
        };

        foreach (array_keys($rows) as $id) {
            $rows[$id]['level'] = $levelOf($id);
        }

        // Deepest first so totals propagate all the way up.
        $ids = array_keys($rows);
        usort($ids, fn ($a, $b) => $rows[$b]['level'] <=> $rows[$a]['level']);

        foreach ($ids as $id) {
            $parent = $rows[$id]['parent_id'];
            if ($parent && isset($rows[$parent])) {
                foreach (['opening_debit', 'opening_credit', 'debit', 'credit'] as $key) {
                    $rows[$parent][$key] += $rows[$id][$key];
                }
            }
        }

        // Keep chart order (by code).
        uasort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return array_values($rows);
    }

    /**
     * Group accounts of the given types with their signed amounts.
     */
    protected function sections(array $types, array $totals): array
    {
        $accounts = $this->accounts()->whereIn('type', $types);
        $result = [];

        foreach ($types as $type) {
            $rows = [];
            foreach ($accounts->where('type', $type) as $account) {
                $t = $totals[$account->id] ?? null;
                if (! $t || $account->is_parent) {
                    continue;
                }
                // Amounts are signed by the account TYPE (so contra accounts such
                // as sales returns or accumulated depreciation reduce their group).
                $typeIsDebit = in_array($type, ['asset', 'expense'], true);
                $amount = $typeIsDebit ? $t['debit'] - $t['credit'] : $t['credit'] - $t['debit'];
                if (abs($amount) < 0.005) {
                    continue;
                }
                $rows[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name_ar, 'amount' => round($amount, 2)];
            }

            $result[$type] = ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)];
        }

        return $result;
    }
}

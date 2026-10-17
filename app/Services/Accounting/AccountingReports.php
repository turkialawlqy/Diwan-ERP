<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\JournalEntry;
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

        // Cost center reports must read lines (balances are not split by cost center).
        if ($costCenterId) {
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

        DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->companyId())
            ->whereIn('e.status', self::STATUSES)
            ->whereBetween('l.date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->whereIn('l.journal_entry_id', function ($q) use ($cashIds, $from, $to) {
                $q->select('journal_entry_id')->from('journal_entry_lines')
                    ->where('company_id', $this->companyId())
                    ->whereIn('account_id', $cashIds ?: [0])
                    ->whereBetween('date', [$from->toDateString(), $to->toDateString().' 23:59:59']);
            })
            ->orderBy('l.journal_entry_id')
            ->select('l.journal_entry_id', 'l.account_id', 'l.debit', 'l.credit', 'e.entry_type')
            ->chunk(5000, function ($lines) use (&$flows, &$openingInRange, $cashIds, $categories) {
                foreach ($lines->groupBy('journal_entry_id') as $entryLines) {
                    $cashNet = 0.0;
                    $others = [];
                    foreach ($entryLines as $line) {
                        if (in_array($line->account_id, $cashIds, true)) {
                            $cashNet += (float) $line->debit - (float) $line->credit;
                        } else {
                            $others[] = $line;
                        }
                    }
                    if (abs($cashNet) < 0.005 || ! $others) {
                        continue; // transfers between cash accounts
                    }
                    // Opening balances are not a cash flow of the period.
                    if ($entryLines->first()->entry_type === 'opening') {
                        $openingInRange += $cashNet;

                        continue;
                    }
                    // In a balanced entry the cash movement equals the other
                    // lines' credits minus debits, so each line's signed
                    // amount is exactly its share of the flow.
                    foreach ($others as $line) {
                        $share = (float) $line->credit - (float) $line->debit;
                        $category = in_array($categories[$line->account_id] ?? null, ['operating', 'investing', 'financing'], true) ? $categories[$line->account_id] : 'operating';
                        $flows[$category][$line->account_id] = ($flows[$category][$line->account_id] ?? 0) + $share;
                    }
                }
            });

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
     * Receivable / payable aging from open documents.
     */
    public function aging(string $kind, Carbon $asOf): array
    {
        [$table, $partyColumn, $partyTable, $dateColumn] = $kind === 'payables'
            ? ['purchase_invoices', 'supplier_id', 'suppliers', 'invoice_date']
            : ['invoices', 'customer_id', 'customers', 'invoice_date'];

        $asOfStr = $asOf->toDateString();
        $driver = DB::getDriverName();
        $daysExpr = $driver === 'sqlite'
            ? "CAST(julianday('{$asOfStr}') - julianday(COALESCE(d.due_date, d.{$dateColumn})) AS INTEGER)"
            : "DATEDIFF('{$asOfStr}', COALESCE(d.due_date, d.{$dateColumn}))";

        $rows = DB::table("{$table} as d")
            ->join("{$partyTable} as p", 'p.id', '=', "d.{$partyColumn}")
            ->where('d.company_id', $this->companyId())
            ->where('d.remaining_amount', '>', 0)
            ->where("d.{$dateColumn}", '<=', $asOfStr.' 23:59:59')
            ->groupBy('p.id', 'p.name')
            ->selectRaw("p.id, p.name,
                SUM(CASE WHEN {$daysExpr} <= 0 THEN d.remaining_amount * d.exchange_rate ELSE 0 END) as current_amount,
                SUM(CASE WHEN {$daysExpr} BETWEEN 1 AND 30 THEN d.remaining_amount * d.exchange_rate ELSE 0 END) as d30,
                SUM(CASE WHEN {$daysExpr} BETWEEN 31 AND 60 THEN d.remaining_amount * d.exchange_rate ELSE 0 END) as d60,
                SUM(CASE WHEN {$daysExpr} BETWEEN 61 AND 90 THEN d.remaining_amount * d.exchange_rate ELSE 0 END) as d90,
                SUM(CASE WHEN {$daysExpr} > 90 THEN d.remaining_amount * d.exchange_rate ELSE 0 END) as d90plus,
                SUM(d.remaining_amount * d.exchange_rate) as total")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'current' => round((float) $r->current_amount, 2),
                'd30' => round((float) $r->d30, 2),
                'd60' => round((float) $r->d60, 2),
                'd90' => round((float) $r->d90, 2),
                'd90plus' => round((float) $r->d90plus, 2),
                'total' => round((float) $r->total, 2),
            ]);

        $totals = [];
        foreach (['current', 'd30', 'd60', 'd90', 'd90plus', 'total'] as $key) {
            $totals[$key] = round($rows->sum($key), 2);
        }

        return ['rows' => $rows->values(), 'totals' => $totals];
    }

    /** Zero-rated supplies that are exports (ZATCA exemption codes). */
    protected const EXPORT_CODES = ['VATEX-SA-32', 'VATEX-SA-33', 'VATEX-SA-34-1', 'VATEX-SA-34-2', 'VATEX-SA-34-3', 'VATEX-SA-34-4', 'VATEX-SA-34-5'];

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
            ->where('v.company_id', $companyId)->whereBetween('v.invoice_date', $range)
            ->selectRaw('i.vat_category as cat, i.vat_exemption_code as code,
                SUM(COALESCE(NULLIF(i.subtotal, 0), i.quantity * i.unit_price - i.discount) * v.exchange_rate) as amount,
                SUM(i.tax_amount * v.exchange_rate) as vat, SUM(i.tax_amount) as raw_vat')
            ->groupBy('i.vat_category', 'i.vat_exemption_code', DB::raw('CASE WHEN i.tax_amount > 0 THEN 1 ELSE 0 END'))
            ->get()->each(fn ($r) => $add($boxOf($r->cat, $r->code, (float) $r->raw_vat), 'amount', (float) $r->amount, (float) $r->vat));

        // Other revenue vouchers with VAT are standard-rated sales.
        $otherRevenue = DB::table('revenues')->where('company_id', $companyId)->whereBetween('revenue_date', $range)->where('tax_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(amount),0) as amount, COALESCE(SUM(tax_amount),0) as vat')->first();
        $add(1, 'amount', (float) $otherRevenue->amount, (float) $otherRevenue->vat);

        // Credit notes reduce the box of the lines they credit.
        $notes = DB::table('credit_notes')->where('company_id', $companyId)->whereIn('status', ['approved', 'refunded'])->whereBetween('credit_note_date', $range)->get();
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
        foreach (DB::table('debit_notes')->where('company_id', $companyId)->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('debit_note_date', $range)->get() as $note) {
            $rate = (float) ($note->exchange_rate ?: 1);
            $add($boxOf(null, null, (float) $note->tax_amount), 'adjustment', (float) $note->subtotal * $rate, (float) $note->tax_amount * $rate);
        }

        // Purchases (taxed lines → box 7, untaxed → box 10), expenses with VAT, minus purchase returns.
        DB::table('purchase_invoice_items as i')->join('purchase_invoices as p', 'p.id', '=', 'i.purchase_invoice_id')
            ->where('p.company_id', $companyId)->whereBetween('p.invoice_date', $range)
            ->selectRaw('CASE WHEN i.tax_amount > 0 THEN 7 ELSE 10 END as box,
                SUM((i.quantity * i.unit_price - i.discount) * p.exchange_rate) as amount, SUM(i.tax_amount * p.exchange_rate) as vat')
            ->groupBy(DB::raw('CASE WHEN i.tax_amount > 0 THEN 7 ELSE 10 END'))
            ->get()->each(fn ($r) => $add((int) $r->box, 'amount', (float) $r->amount, (float) $r->vat));

        $expenses = DB::table('expenses')->where('company_id', $companyId)->whereBetween('expense_date', $range)->where('tax_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(amount),0) as amount, COALESCE(SUM(tax_amount),0) as vat')->first();
        $add(7, 'amount', (float) $expenses->amount, (float) $expenses->vat);

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

<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\ApprovalRequest;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use App\Models\PaymentAllocation;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Services\ActiveBranch;
use App\Services\TenantManager;
use App\Services\Workflow\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounting control reports: what needs a person's attention in the books (drafts, missing references and
 * dimensions), how the sub-ledgers agree with the control accounts, party balances with credit limits,
 * cash / bank movement with unreconciled lines, and accounts nobody uses.
 */
class AccountingControls
{
    public function __construct(
        protected AccountingReports $reports,
        protected PartyBalanceReconciler $reconciler,
        protected PartyAccounts $parties,
        protected AccountMap $map,
        protected DimensionPolicy $dimensions,
    ) {}

    protected function companyId(): int
    {
        return (int) app(TenantManager::class)->getTenantId();
    }

    /** Posted lines (and their entries) of the active branches. */
    protected function lines()
    {
        $branches = app(ActiveBranch::class)->scopeIds();

        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->companyId())
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->when($branches !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('e.branch_id', $branches)->orWhereNull('e.branch_id')));
    }

    /**
     * Items needing attention, by kind. Each row: kind, reference, date, description, age (days), who.
     *
     * @return array<string, array{label: string, rows: array<int, array>}>
     */
    public function exceptions(?Carbon $today = null): array
    {
        $today ??= now();
        $sections = [];

        $sections['draft'] = ['label' => 'قيود مسودة غير مرحّلة', 'rows' => JournalEntry::with('creator:id,name')->where('status', JournalEntry::STATUS_DRAFT)
            ->orderBy('date')->limit(200)->get()->map(fn ($e) => $this->item($e->entry_number, $e->date, $e->description, $today, $e->creator?->name))->all()];

        $sections['unbalanced'] = ['label' => 'قيود مرحّلة غير متوازنة', 'rows' => JournalEntry::whereIn('status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->whereRaw('ABS(total_debit - total_credit) > 0.005')->orderBy('date')->limit(200)->get()
            ->map(fn ($e) => $this->item($e->entry_number, $e->date, $e->description, $today, null, 'الفرق '.number_format(abs((float) $e->total_debit - (float) $e->total_credit), 2)))->all()];

        $sections['no_reference'] = ['label' => 'قيود يدوية مرحّلة بلا مرجع ولا مستند مصدر', 'rows' => JournalEntry::with('creator:id,name')->where('status', JournalEntry::STATUS_POSTED)
            ->whereIn('entry_type', ['manual', 'adjustment'])->whereNull('source_type')->where(fn ($q) => $q->whereNull('reference')->orWhere('reference', ''))
            ->orderByDesc('date')->limit(200)->get()->map(fn ($e) => $this->item($e->entry_number, $e->date, $e->description, $today, $e->creator?->name))->all()];

        // Lines on accounts whose dimension the company's policy requires (last 12 months).
        $rows = [];
        foreach (DimensionPolicy::DIMENSIONS as $dimension => $label) {
            $required = Account::where('is_parent', false)->get()->filter(fn ($a) => $this->dimensions->required($a, $dimension))->pluck('id')->all();
            if (! $required) {
                continue;
            }
            $column = $dimension === 'cost_center' ? 'cost_center_id' : 'project_id';
            $this->lines()->whereIn('l.account_id', $required)->whereNull("l.{$column}")->where('l.date', '>=', $today->copy()->subYear()->toDateString())
                ->where('e.entry_type', '!=', 'opening')->orderByDesc('l.date')->limit(100)
                ->get(['e.entry_number', 'l.date', 'l.description', 'l.account_id'])
                ->each(function ($l) use (&$rows, $today, $label) {
                    $rows[] = $this->item($l->entry_number, Carbon::parse($l->date), $l->description, $today, null, "بلا {$label}");
                });
        }
        $sections['no_dimension'] = ['label' => 'أسطر بلا بعد تحليلي مطلوب (آخر 12 شهرًا)', 'rows' => $rows];

        $inactive = Account::where('is_active', false)->pluck('id')->all();
        $sections['inactive_used'] = ['label' => 'حسابات موقوفة عليها حركة (آخر 90 يومًا)', 'rows' => $inactive ? $this->lines()->whereIn('l.account_id', $inactive)
            ->where('l.date', '>=', $today->copy()->subDays(90)->toDateString())->orderByDesc('l.date')->limit(100)
            ->get(['e.entry_number', 'l.date', 'l.description'])->map(fn ($l) => $this->item($l->entry_number, Carbon::parse($l->date), $l->description, $today))->all() : []];

        return $sections;
    }

    protected function item(?string $reference, $date, ?string $description, Carbon $today, ?string $who = null, ?string $detail = null): array
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return ['reference' => (string) $reference, 'date' => $date->toDateString(), 'description' => (string) $description, 'age' => (int) $date->copy()->startOfDay()->diffInDays($today->copy()->startOfDay()), 'who' => $who ?? '', 'detail' => $detail ?? ''];
    }

    /**
     * Control accounts against what the party sub-ledgers say, then the parties that disagree.
     *
     * @return array{control: array<int, array>, parties: array<int, array>}
     */
    public function partyReconciliation(): array
    {
        $companyId = $this->companyId();
        $control = [];
        foreach (['customer' => ['العملاء', 1, $this->parties->receivableAccountIds($companyId), Customer::class], 'supplier' => ['الموردون', -1, array_filter([$this->map->find('payables', $companyId)]), Supplier::class]] as $kind => [$label, $sign, $accounts, $model]) {
            $rows = $this->lines()->whereIn('l.account_id', $accounts ?: [0])->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as total, COALESCE(SUM(CASE WHEN l.party_type = ? THEN l.debit - l.credit ELSE 0 END), 0) as tagged', [$kind])->first();
            $ledger = round($sign * (float) $rows->total, 2);
            $tagged = round($sign * (float) $rows->tagged, 2);
            $stored = round((float) $model::sum('current_balance'), 2);
            $control[] = ['label' => $label, 'control' => $ledger, 'tagged' => $tagged, 'untagged' => round($ledger - $tagged, 2), 'stored' => $stored, 'difference' => round($stored - $ledger, 2)];
        }

        return ['control' => $control, 'parties' => $this->reconciler->differences($companyId)];
    }

    /**
     * Opening / debit / credit / closing per party from the ledger, with credit limit and unapplied receipts or payments.
     *
     * @return array<int, array>
     */
    public function partyBalances(string $kind, Carbon $from, Carbon $to): array
    {
        $companyId = $this->companyId();
        $customers = $kind === 'customer';
        $accounts = $customers ? $this->parties->receivableAccountIds($companyId) : array_filter([$this->map->find('payables', $companyId)]);
        $sign = $customers ? 1 : -1;
        $range = [$from->toDateString(), $to->toDateString().' 23:59:59'];

        $rows = $this->lines()->whereIn('l.account_id', $accounts ?: [0])->where('l.party_type', $kind)->where('l.date', '<=', $to->toDateString().' 23:59:59')
            ->groupBy('l.party_id')
            ->selectRaw('l.party_id, SUM(CASE WHEN l.date < ? THEN l.debit - l.credit ELSE 0 END) as opening, SUM(CASE WHEN l.date >= ? THEN l.debit ELSE 0 END) as debit, SUM(CASE WHEN l.date >= ? THEN l.credit ELSE 0 END) as credit', [$from->toDateString(), $range[0], $range[0]])
            ->get()->keyBy('party_id');

        $model = $customers ? Customer::class : Supplier::class;
        $names = $model::whereIn('id', $rows->keys())->get()->keyBy('id');
        $unapplied = $this->unapplied($customers);

        $result = [];
        foreach ($rows as $id => $r) {
            $opening = round($sign * (float) $r->opening, 2);
            $debit = round((float) $r->debit, 2);
            $credit = round((float) $r->credit, 2);
            $closing = round($opening + $sign * ($debit - $credit), 2);
            if (abs($opening) < 0.005 && abs($closing) < 0.005 && $debit == 0.0 && $credit == 0.0) {
                continue;
            }
            $limit = $customers ? (float) ($names[$id]->credit_limit ?? 0) : 0.0;
            $result[] = ['name' => $names[$id]->name ?? '#'.$id, 'opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing' => $closing,
                'limit' => $customers ? $limit : null, 'over_limit' => $customers && $limit > 0 && $closing > $limit + 0.004,
                'unapplied' => round((float) ($unapplied[$id] ?? 0), 2)];
        }
        usort($result, fn ($a, $b) => $b['closing'] <=> $a['closing']);

        return $result;
    }

    /** Receipts / payments (not cancelled) minus what has been allocated to documents, per party. */
    protected function unapplied(bool $customers): array
    {
        if ($customers) {
            $paid = CustomerPayment::where('status', '!=', 'cancelled')->groupBy('customer_id')->selectRaw('customer_id as party, SUM(amount) as total')->pluck('total', 'party');
            $allocated = PaymentAllocation::join('customer_payments as p', 'p.id', '=', 'payment_allocations.customer_payment_id')->where('p.status', '!=', 'cancelled')
                ->groupBy('p.customer_id')->selectRaw('p.customer_id as party, SUM(payment_allocations.amount) as total')->pluck('total', 'party');
        } else {
            $paid = SupplierPayment::where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))->groupBy('supplier_id')->selectRaw('supplier_id as party, SUM(amount) as total')->pluck('total', 'party');
            $allocated = SupplierPaymentAllocation::join('supplier_payments as p', 'p.id', '=', 'supplier_payment_allocations.supplier_payment_id')->where(fn ($q) => $q->whereNull('p.status')->orWhere('p.status', '!=', 'cancelled'))
                ->groupBy('p.supplier_id')->selectRaw('p.supplier_id as party, SUM(supplier_payment_allocations.amount) as total')->pluck('total', 'party');
        }

        return $paid->map(fn ($total, $party) => max(0, (float) $total - (float) ($allocated[$party] ?? 0)))->all();
    }

    /**
     * Each cash box / bank account: opening, receipts, payments, closing; plus bank lines not yet reconciled, by age.
     *
     * @return array{accounts: array<int, array>, unreconciled: array<int, array>}
     */
    public function cashMovement(Carbon $from, Carbon $to): array
    {
        $boxes = BankAccount::whereNotNull('account_id')->orderBy('type')->orderBy('name')->get(['id', 'name', 'type', 'account_id']);
        $ids = $boxes->pluck('account_id')->all();
        $before = $ids ? $this->reports->totalsBetween(null, $from->copy()->subDay(), $ids) : [];
        $during = $ids ? $this->reports->totalsBetween($from, $to, $ids) : [];

        $accounts = $boxes->map(function ($b) use ($before, $during) {
            $opening = round(($before[$b->account_id]['debit'] ?? 0) - ($before[$b->account_id]['credit'] ?? 0), 2);
            $in = round((float) ($during[$b->account_id]['debit'] ?? 0), 2);
            $out = round((float) ($during[$b->account_id]['credit'] ?? 0), 2);

            return ['name' => $b->name, 'type' => $b->type === 'bank' ? 'بنك' : 'صندوق', 'opening' => $opening, 'in' => $in, 'out' => $out, 'closing' => round($opening + $in - $out, 2)];
        })->all();

        $bankIds = $boxes->where('type', 'bank')->pluck('account_id')->all();
        $buckets = ['0-30' => [0, 30], '31-60' => [31, 60], '61-90' => [61, 90], 'أكثر من 90' => [91, PHP_INT_MAX]];
        $unreconciled = [];
        foreach ($buckets as $label => [$min, $max]) {
            $unreconciled[$label] = ['label' => $label.' يوم', 'count' => 0, 'net' => 0.0];
        }
        if ($bankIds) {
            $this->lines()->whereIn('l.account_id', $bankIds)->whereNull('l.reconciliation_id')->where('l.date', '<=', $to->toDateString().' 23:59:59')
                ->get(['l.date', 'l.debit', 'l.credit'])->each(function ($l) use ($to, $buckets, &$unreconciled) {
                    $age = (int) Carbon::parse($l->date)->startOfDay()->diffInDays($to->copy()->startOfDay());
                    foreach ($buckets as $label => [$min, $max]) {
                        if ($age >= $min && $age <= $max) {
                            $unreconciled[$label]['count']++;
                            $unreconciled[$label]['net'] = round($unreconciled[$label]['net'] + (float) $l->debit - (float) $l->credit, 2);
                        }
                    }
                });
        }

        return ['accounts' => $accounts, 'unreconciled' => array_values($unreconciled)];
    }

    /**
     * Active leaf accounts with no posted movement for $days days (or ever), with their balance.
     *
     * @return array<int, array>
     */
    public function dormantAccounts(int $days, ?Carbon $today = null): array
    {
        $today ??= now();
        $cutoff = $today->copy()->subDays($days)->toDateString();
        $last = $this->lines()->groupBy('l.account_id')->selectRaw('l.account_id, MAX(l.date) as last_date')->pluck('last_date', 'account_id');
        $totals = $this->reports->totalsBetween(null, $today);

        return Account::where('is_parent', false)->where('is_active', true)->where('is_system', false)->orderBy('code')->get(['id', 'code', 'name_ar', 'type', 'nature'])
            ->filter(fn ($a) => ! isset($last[$a->id]) || substr((string) $last[$a->id], 0, 10) < $cutoff)
            ->map(function ($a) use ($last, $totals, $today) {
                $balance = $a->signedBalance((float) ($totals[$a->id]['debit'] ?? 0), (float) ($totals[$a->id]['credit'] ?? 0));
                $date = isset($last[$a->id]) ? substr((string) $last[$a->id], 0, 10) : null;

                return ['code' => $a->code, 'name' => $a->name_ar, 'last' => $date ?? 'لا حركة', 'days' => $date ? (int) Carbon::parse($date)->startOfDay()->diffInDays($today->copy()->startOfDay()) : null, 'balance' => round($balance, 2)];
            })->values()->all();
    }

    /**
     * Expense accounts whose spend in a month is unusually high against their own history:
     * the month's net debit exceeds the mean of the previous $months months by more than
     * $k standard deviations (and by at least $minAmount). A review signal, not a verdict.
     *
     * @return array<int, array>
     */
    public function unusualExpenses(int $months = 12, float $k = 2.0, float $minAmount = 0.0, ?Carbon $today = null): array
    {
        $today ??= now();
        $current = $today->copy()->startOfMonth();
        $from = $current->copy()->subMonths($months);
        $accounts = Account::where('type', 'expense')->where('is_parent', false)->get(['id', 'code', 'name_ar'])->keyBy('id');
        if ($accounts->isEmpty()) {
            return [];
        }
        $closingIds = DB::table('journal_entries')->where('company_id', $this->companyId())->where('entry_type', 'closing')->pluck('id');

        $byMonth = [];
        $this->lines()->whereIn('l.account_id', $accounts->keys())
            ->when($closingIds->isNotEmpty(), fn ($q) => $q->whereNotIn('e.id', $closingIds)->where(fn ($w) => $w->whereNull('e.reversal_of_id')->orWhereNotIn('e.reversal_of_id', $closingIds)))
            ->where('l.date', '>=', $from->toDateString())->where('l.date', '<=', $today->copy()->endOfMonth()->toDateString().' 23:59:59')
            ->groupBy('l.account_id', 'l.date')->selectRaw('l.account_id, l.date, SUM(l.debit - l.credit) as net')->get()
            ->each(function ($r) use (&$byMonth) {
                $m = substr((string) $r->date, 0, 7);
                $byMonth[$r->account_id][$m] = ($byMonth[$r->account_id][$m] ?? 0.0) + (float) $r->net;
            });

        $rows = [];
        $currentKey = $current->format('Y-m');
        foreach ($byMonth as $accountId => $perMonth) {
            $history = [];
            for ($i = 1; $i <= $months; $i++) {
                $history[] = $perMonth[$current->copy()->subMonths($i)->format('Y-m')] ?? 0.0;
            }
            $mean = array_sum($history) / max(1, count($history));
            $std = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $history)) / max(1, count($history)));
            $now = round($perMonth[$currentKey] ?? 0.0, 2);
            $threshold = round($mean + $k * $std, 2);
            if ($now > $threshold && ($now - $mean) >= $minAmount && $now > 0) {
                $rows[] = ['code' => $accounts[$accountId]->code, 'name' => $accounts[$accountId]->name_ar, 'month' => $currentKey, 'amount' => $now, 'mean' => round($mean, 2), 'std' => round($std, 2), 'threshold' => $threshold, 'excess' => round($now - $mean, 2)];
            }
        }
        usort($rows, fn ($a, $b) => $b['excess'] <=> $a['excess']);

        return $rows;
    }

    /**
     * A budget's lines set for one kind of dimension (cost centre / project / branch) against what the ledger
     * holds for each value, over a range of months (YYYYMM). Expense variance is actual − planned (positive = over),
     * revenue variance is planned − actual.
     *
     * @return array<int, array>
     */
    public function budgetByDimension(Budget $budget, string $dimension, int $fromPeriod, int $toPeriod): array
    {
        $column = BudgetService::DIMENSIONS[$dimension];
        $service = app(BudgetService::class);
        $names = match ($dimension) {
            'cost_center' => CostCenter::pluck('name_ar', 'id'),
            'project' => Project::pluck('name', 'id'),
            default => Branch::pluck('name', 'id'),
        };
        $accounts = Account::whereIn('id', BudgetLine::where('budget_id', $budget->id)->whereNotNull($column)->pluck('account_id'))->get(['id', 'code', 'name_ar', 'type'])->keyBy('id');
        $from = Carbon::createFromFormat('Ym', (string) $fromPeriod)->startOfMonth();
        $to = Carbon::createFromFormat('Ym', (string) $toPeriod)->endOfMonth();

        $rows = [];
        BudgetLine::where('budget_id', $budget->id)->whereNotNull($column)->whereBetween('period', [$fromPeriod, $toPeriod])
            ->groupBy($column, 'account_id')->selectRaw($column.' as dim_id, account_id, SUM(amount) as planned')->get()
            ->each(function ($r) use (&$rows, $service, $dimension, $accounts, $names, $from, $to) {
                $account = $accounts[$r->account_id] ?? null;
                if (! $account) {
                    return;
                }
                $planned = round((float) $r->planned, 2);
                $net = $service->dimensionActual($dimension, (int) $r->dim_id, (int) $r->account_id, $from, $to);
                $actual = round($account->type === 'expense' ? $net : -$net, 2);
                $variance = round($account->type === 'expense' ? $actual - $planned : $planned - $actual, 2);
                $rows[] = ['dimension' => $names[$r->dim_id] ?? '#'.$r->dim_id, 'account' => $account->code.' - '.$account->name_ar, 'planned' => $planned, 'actual' => $actual,
                    'variance' => $variance, 'pct' => $planned != 0.0 ? round($actual / $planned * 100, 1) : null];
            });
        usort($rows, fn ($a, $b) => [$a['dimension'], $a['account']] <=> [$b['dimension'], $b['account']]);

        return $rows;
    }

    /**
     * Approval requests raised by the approval gate (payments, expenses, credit notes, journals, payroll, loans...) by
     * document type or by the user who decided them: how many wait, for how long, how many are overdue, and the outcomes.
     *
     * @return array<int, array>
     */
    public function approvalFlow(string $group = 'type', int $overdueDays = 7, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $byUser = $group === 'approver';
        $users = $byUser ? User::whereIn('id', ApprovalRequest::whereNotNull('decided_by')->pluck('decided_by')->unique())->pluck('name', 'id') : collect();
        $rows = [];

        foreach (ApprovalRequest::orderBy('id')->get() as $r) {
            $key = $byUser ? ($r->decided_by ? ($users[$r->decided_by] ?? '#'.$r->decided_by) : 'بانتظار قرار') : (ApprovalService::DOCUMENT_TYPES[$r->document_type] ?? $r->document_type);
            $row = &$rows[$key];
            $row ??= ['group' => $key, 'pending' => 0, 'pending_amount' => 0.0, 'overdue' => 0, 'oldest' => null, 'approved' => 0, 'rejected' => 0, 'used' => 0, 'decide_hours' => []];
            if ($r->status === 'pending') {
                $age = (int) $r->created_at->copy()->startOfDay()->diffInDays($today);
                $row['pending']++;
                $row['pending_amount'] = round($row['pending_amount'] + (float) $r->amount, 2);
                $row['oldest'] = max($row['oldest'] ?? 0, $age);
                if ($age > $overdueDays) {
                    $row['overdue']++;
                }
            } else {
                $row[$r->status] = ($row[$r->status] ?? 0) + 1;
                if ($r->decided_at) {
                    $row['decide_hours'][] = $r->created_at->diffInMinutes($r->decided_at) / 60;
                }
            }
            unset($row);
        }

        return collect($rows)->map(function ($r) {
            $hours = $r['decide_hours'];
            $r['avg_hours'] = $hours ? round(array_sum($hours) / count($hours), 1) : null;
            unset($r['decide_hours']);

            return $r;
        })->sortByDesc('pending')->values()->all();
    }
}

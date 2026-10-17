<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Cheque;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\RecurringInvoice;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\ExchangeRates;
use App\Services\Projects\ProjectReport;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Management dashboards. All money is in base currency.
 */
class Insights
{
    public function __construct(protected AccountingReports $reports, protected ExchangeRates $rates) {}

    /**
     * Expected cash over the next weeks: what is in the bank today, plus
     * receivables, recurring invoices and incoming cheques as they fall due,
     * minus payables, outgoing cheques and payroll. Overdue items are shown
     * separately rather than assumed to arrive next week.
     */
    public function cashForecast(int $weeks = 13, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $end = $today->copy()->addWeeks($weeks)->subDay();

        $cashIds = $this->reports->cashAccountIds();
        $opening = round((float) Account::whereIn('id', $cashIds)->get()->sum(fn ($a) => (float) $a->balance), 2);

        $buckets = [];
        for ($w = 0; $w < $weeks; $w++) {
            $start = $today->copy()->addWeeks($w);
            $buckets[$w] = ['start' => $start->toDateString(), 'end' => $start->copy()->addDays(6)->toDateString(), 'receivables' => 0.0, 'recurring' => 0.0, 'cheques_in' => 0.0, 'payables' => 0.0, 'cheques_out' => 0.0, 'payroll' => 0.0];
        }
        $week = fn (Carbon $date) => intdiv((int) $today->diffInDays($date), 7);
        $add = function (string $key, $date, float $amount) use (&$buckets, $week, $today, $end) {
            $date = Carbon::parse($date)->startOfDay();
            if ($date->gte($today) && $date->lte($end)) {
                $buckets[$week($date)][$key] += $amount;
            }
        };

        $due = 'COALESCE(due_date, invoice_date)';
        $overdue = ['receivables' => 0.0, 'payables' => 0.0];
        foreach ([[Invoice::class, 'receivables'], [PurchaseInvoice::class, 'payables']] as [$model, $key]) {
            $model::where('remaining_amount', '>', 0)->selectRaw("{$due} as due, SUM(remaining_amount * exchange_rate) as amount")
                ->groupByRaw($due)->get()
                ->each(function ($row) use ($add, $key, $today, &$overdue) {
                    Carbon::parse($row->due)->lt($today) ? $overdue[$key] += (float) $row->amount : $add($key, $row->due, (float) $row->amount);
                });
        }

        Cheque::whereIn('status', ['pending', 'deposited'])->whereBetween('due_date', [$today->toDateString(), $end->toDateString()])->get(['direction', 'due_date', 'amount'])
            ->each(fn ($c) => $add($c->direction === 'incoming' ? 'cheques_in' : 'cheques_out', $c->due_date, (float) $c->amount));

        RecurringInvoice::where('status', 'active')->get()->each(function (RecurringInvoice $t) use ($add, $end) {
            $rate = $this->rates->isBase($t->currency_code) ? 1.0 : rescue(fn () => $this->rates->rate($t->currency_code), 1.0, false);
            $date = $t->next_run_date->copy();
            $issued = $t->generated_count;
            while ($date->lte($end) && (! $t->end_date || $date->lte($t->end_date)) && (! $t->max_count || $issued < $t->max_count)) {
                $add('recurring', $date->copy()->addDays((int) ($t->due_days ?? $t->customer?->payment_terms_days ?? 0)), $t->estimatedTotal() * $rate);
                $date = $t->nextAfter($date);
                $issued++;
            }
        });

        // Payroll: last approved run, expected at each month end not yet paid.
        $lastPayroll = Payroll::whereIn('status', ['approved', 'paid'])->orderByDesc('year')->orderByDesc('month')->first();
        if ($lastPayroll) {
            for ($m = $today->copy()->startOfMonth(); $m->lte($end); $m->addMonth()) {
                $paid = Payroll::where('year', $m->year)->where('month', $m->month)->where('status', 'paid')->exists();
                if (! $paid) {
                    $add('payroll', $m->copy()->endOfMonth(), (float) $lastPayroll->net_total);
                }
            }
        }

        $balance = $opening;
        $rows = array_map(function ($b) use (&$balance) {
            $in = round($b['receivables'] + $b['recurring'] + $b['cheques_in'], 2);
            $out = round($b['payables'] + $b['cheques_out'] + $b['payroll'], 2);
            $balance = round($balance + $in - $out, 2);

            return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $b) + ['inflow' => $in, 'outflow' => $out, 'net' => round($in - $out, 2), 'balance' => $balance];
        }, $buckets);

        return [
            'opening' => $opening,
            'weeks' => array_values($rows),
            'closing' => $balance,
            'lowest' => collect($rows)->min('balance') ?? $opening,
            'overdue' => array_map(fn ($v) => round($v, 2), $overdue),
            'payroll_estimate' => $lastPayroll ? (float) $lastPayroll->net_total : null,
        ];
    }

    /** Receivable / payable aging with the largest overdue parties. */
    public function aging(?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $result = [];
        foreach (['receivables', 'payables'] as $kind) {
            $aging = $this->reports->aging($kind, $asOf);
            $result[$kind] = [
                'totals' => $aging['totals'],
                'top' => collect($aging['rows'])->map(fn ($r) => $r + ['overdue' => round($r['total'] - $r['current'], 2)])
                    ->filter(fn ($r) => $r['overdue'] > 0)->sortByDesc('overdue')->take(10)->values(),
            ];
        }

        return $result;
    }

    /** Sales margin by product and by customer, and project profitability. */
    public function profitability(Carbon $from, Carbon $to): array
    {
        $range = [$from->toDateString(), $to->toDateString().' 23:59:59'];
        $invoiceType = (new Invoice)->getMorphClass();

        $revenueByProduct = DB::table('invoice_items as i')->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->where('v.company_id', $this->companyId())->whereBetween('v.invoice_date', $range)->whereNotNull('i.product_id')
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, SUM(i.quantity) as qty, SUM((i.quantity * i.unit_price - COALESCE(i.discount, 0)) * v.exchange_rate) as revenue')
            ->get()->keyBy('product_id');

        $costByProduct = DB::table('stock_movements as m')->join('invoices as v', fn ($j) => $j->on('v.id', '=', 'm.source_id')->where('m.source_type', '=', $invoiceType))
            ->where('m.company_id', $this->companyId())->whereBetween('v.invoice_date', $range)
            ->groupBy('m.product_id')->selectRaw('m.product_id, -SUM(m.total_cost) as cost')->pluck('cost', 'product_id');

        $names = DB::table('products')->whereIn('id', $revenueByProduct->keys())->pluck('name_ar', 'id');
        $products = $revenueByProduct->map(function ($r) use ($costByProduct, $names) {
            $revenue = round((float) $r->revenue, 2);
            $cost = round((float) ($costByProduct[$r->product_id] ?? 0), 2);

            return ['id' => $r->product_id, 'name' => $names[$r->product_id] ?? '—', 'qty' => (float) $r->qty, 'revenue' => $revenue, 'cost' => $cost, 'margin' => round($revenue - $cost, 2), 'margin_pct' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : null];
        })->sortByDesc('revenue')->take(20)->values();

        $customers = DB::table('invoices as v')->join('customers as c', 'c.id', '=', 'v.customer_id')
            ->leftJoinSub(
                DB::table('stock_movements')->where('source_type', $invoiceType)->groupBy('source_id')->selectRaw('source_id, -SUM(total_cost) as cost'),
                'sc', 'sc.source_id', '=', 'v.id')
            ->where('v.company_id', $this->companyId())->whereBetween('v.invoice_date', $range)
            ->groupBy('c.id', 'c.name')
            ->selectRaw('c.id, c.name, SUM(v.subtotal * v.exchange_rate) as revenue, SUM(COALESCE(sc.cost, 0)) as cost, COUNT(v.id) as invoices')
            ->orderByDesc('revenue')->limit(20)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'invoices' => (int) $r->invoices, 'revenue' => round((float) $r->revenue, 2), 'cost' => round((float) $r->cost, 2), 'margin' => round($r->revenue - $r->cost, 2), 'margin_pct' => $r->revenue > 0 ? round(($r->revenue - $r->cost) / $r->revenue * 100, 1) : null]);

        $projects = Project::whereIn('status', ['planning', 'active', 'on_hold'])->orderByDesc('id')->limit(20)->get()
            ->map(function (Project $p) {
                $s = rescue(fn () => app(ProjectReport::class)->summary($p), null, false);

                return $s ? ['id' => $p->id, 'name' => $p->name, 'code' => $p->code] + array_intersect_key($s, array_flip(['contract_value', 'billed_to_date', 'progress_percent', 'revenue', 'actual_cost', 'estimated_cost', 'gross_profit', 'margin_percent', 'projected_margin', 'collected'])) : null;
            })->filter()->values();

        return compact('products', 'customers', 'projects');
    }

    /** Year-to-date budget vs actual for the current fiscal year's approved budget. */
    public function budget(?Carbon $asOf = null): ?array
    {
        $asOf ??= now();
        $year = FiscalYear::whereDate('start_date', '<=', $asOf)->whereDate('end_date', '>=', $asOf)->first();
        $budget = $year ? Budget::where('fiscal_year_id', $year->id)->where('status', 'approved')->latest('id')->first() : null;
        if (! $budget) {
            return null;
        }

        $from = (int) Carbon::parse($year->start_date)->format('Ym');
        $to = (int) $asOf->format('Ym');
        $planned = DB::table('budget_lines')->where('budget_id', $budget->id)->whereBetween('period', [$from, $to])
            ->groupBy('account_id')->selectRaw('account_id, SUM(amount) as amount')->pluck('amount', 'account_id');
        $accounts = Account::whereIn('id', $planned->keys())->get(['id', 'code', 'name_ar', 'type']);
        // Actuals exclude the year-end closing entry (it zeroes revenue and expenses).
        $actual = collect(app(AccountingReports::class)->totalsBetween(
            Carbon::parse($year->start_date)->startOfDay(), $asOf->copy()->endOfMonth(), $accounts->pluck('id')->all() ?: [0], null, true,
        ))->map(fn ($t) => (object) ['d' => $t['debit'], 'c' => $t['credit']]);

        $rows = $accounts->map(function ($a) use ($planned, $actual) {
            $b = $actual->get($a->id);
            $act = $b ? round($a->type === 'expense' ? $b->d - $b->c : $b->c - $b->d, 2) : 0.0;
            $plan = round((float) $planned[$a->id], 2);
            // Positive variance is favourable: revenue above plan or expense below.
            $variance = round($a->type === 'expense' ? $plan - $act : $act - $plan, 2);

            return ['code' => $a->code, 'name' => $a->name_ar, 'type' => $a->type, 'planned' => $plan, 'actual' => $act, 'variance' => $variance, 'used_pct' => $plan != 0.0 ? round($act / $plan * 100, 1) : null];
        });

        $sum = fn ($type, $key) => round($rows->where('type', $type)->sum($key), 2);

        return [
            'budget' => ['id' => $budget->id, 'name' => $budget->name, 'year' => $year->name ?? Carbon::parse($year->start_date)->year],
            'totals' => [
                'revenue' => ['planned' => $sum('revenue', 'planned'), 'actual' => $sum('revenue', 'actual')],
                'expense' => ['planned' => $sum('expense', 'planned'), 'actual' => $sum('expense', 'actual')],
            ],
            'worst' => $rows->sortBy('variance')->take(10)->values(),
        ];
    }

    protected function companyId(): int
    {
        return (int) app(TenantManager::class)->getTenantId();
    }
}

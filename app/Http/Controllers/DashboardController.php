<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Services\ActiveBranch;
use App\Services\ActiveFiscalYear;
use App\Services\Reports\Insights;
use App\Services\Saas\OnboardingChecklist;
use App\Services\TenantManager;
use App\Support\CurrencyNames;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display tenant ERP dashboard with real data from the database.
     */
    public function index(Request $request, TenantManager $tenantManager): Response
    {
        $company = $tenantManager->getTenant() ?? $request->user()?->company;

        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

        // === KPI Metrics (Real Data) ===
        // Range predicates (not whereDate/whereMonth) so (company_id, invoice_date)
        // indexes are used; aggregates are cached briefly per company.
        // Figures follow the branch and fiscal year chosen in the top bar.
        $year = app(ActiveFiscalYear::class)->current();
        $scope = app(ActiveBranch::class)->scopeKey();
        $cacheKey = 'dashboard:'.$tenantManager->getTenantId().':'.$scope.':'.($year?->id ?? 0).':'.$today->toDateString();
        $cached = Cache::remember($cacheKey.':kpis', now()->addMinutes(5), function () use ($today, $startOfMonth, $lastMonthStart, $lastMonthEnd) {
            return [
                'today' => (float) Invoice::whereBetween('invoice_date', [$today->toDateString(), $today->toDateString().' 23:59:59'])->sumBase('total'),
                'month' => (float) Invoice::where('invoice_date', '>=', $startOfMonth->toDateString())->sumBase('total'),
                'last_month' => (float) Invoice::whereBetween('invoice_date', [$lastMonthStart->toDateString(), $lastMonthEnd->toDateTimeString()])->sumBase('total'),
                'month_count' => Invoice::where('invoice_date', '>=', $startOfMonth->toDateString())->count(),
                'last_month_count' => Invoice::whereBetween('invoice_date', [$lastMonthStart->toDateString(), $lastMonthEnd->toDateTimeString()])->count(),
                'receivables' => (float) Customer::where('status', 'active')->sum('current_balance'),
            ];
        });

        $todaySales = $cached['today'];
        $monthSales = $cached['month'];
        $lastMonthSales = $cached['last_month'];
        $monthSalesTrend = $lastMonthSales > 0
            ? round((($monthSales - $lastMonthSales) / $lastMonthSales) * 100, 1)
            : 0;

        $invoicesCount = $cached['month_count'];
        $lastMonthInvoicesCount = $cached['last_month_count'];
        $invoicesCountTrend = $lastMonthInvoicesCount > 0
            ? round((($invoicesCount - $lastMonthInvoicesCount) / $lastMonthInvoicesCount) * 100, 1)
            : 0;

        $receivables = $cached['receivables'];

        $kpis = [
            'today_sales' => number_format($todaySales, 2),
            'month_sales' => number_format($monthSales, 2),
            'month_sales_trend' => $monthSalesTrend,
            'invoices_count' => $invoicesCount,
            'invoices_count_trend' => $invoicesCountTrend,
            'receivables' => number_format($receivables, 2),
            'receivables_trend' => 0,
            'inventory_value' => number_format(
                (float) Product::where('is_active', true)->sum(DB::raw('selling_price')),
                2
            ),
            'low_stock_count' => 0, // Will be computed when stock tracking is fully built
        ];

        // === Recent Sales Invoices (Real Data) ===
        $recentInvoices = Invoice::with('customer')
            ->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc')
            ->take(5)
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'number' => $inv->invoice_number,
                'customer_name' => $inv->customer?->name ?? $inv->customer?->company_name ?? '—',
                'date' => $inv->invoice_date?->format('Y-m-d'),
                'total' => number_format((float) $inv->total, 2),
                'status' => $inv->payment_status,
            ]);

        // === Top Selling Products (Real Data — by POS + Invoice items) ===
        $topProducts = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->select(
                'invoice_items.item_name as name',
                DB::raw('SUM(invoice_items.quantity) as sold_units'),
                DB::raw('SUM(invoice_items.total) as revenue')
            )
            ->where('invoices.company_id', $tenantManager->getTenantId())
            ->where('invoices.invoice_date', '>=', Carbon::now()->subDays(90)->toDateString())
            ->groupBy('invoice_items.item_name')
            ->orderByDesc('revenue')
            ->limit(4)
            ->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'category' => '—',
                'sold_units' => (int) $p->sold_units,
                'revenue' => number_format((float) $p->revenue, 2),
            ]);

        // === Monthly Sales Chart (Real Data — last 6 months) ===
        $months = [];
        $salesData = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $months[] = $date->translatedFormat('F');
            $salesData[] = Cache::remember(
                $cacheKey.':month:'.$date->format('Y-m'),
                now()->addMinutes($i === 0 ? 5 : 60),
                fn () => (float) Invoice::whereBetween('invoice_date', [
                    $date->copy()->startOfMonth()->toDateString(),
                    $date->copy()->endOfMonth()->toDateTimeString(),
                ])->sumBase('total')
            );
        }

        $salesChart = [
            'months' => $months,
            'series' => [
                [
                    'name' => 'المبيعات ('.CurrencyNames::for(app(TenantManager::class)->getCompany()?->currency)[2].')',
                    'data' => $salesData,
                ],
            ],
        ];

        // === Fiscal year: revenue vs expenses per month, and where the money goes ===
        $yearStart = $year?->start_date?->copy() ?? Carbon::now()->startOfYear();
        $yearEnd = $year?->end_date?->copy() ?? Carbon::now()->endOfYear();
        $yearData = Cache::remember($cacheKey.':year', now()->addMinutes(5), function () use ($yearStart, $yearEnd) {
            $labels = [];
            $revenue = [];
            $expense = [];
            $month = $yearStart->copy()->startOfMonth();
            while ($month->lte($yearEnd)) {
                $from = $month->toDateString();
                $to = $month->copy()->endOfMonth()->toDateTimeString();
                $labels[] = $month->translatedFormat('F');
                $revenue[] = (float) Invoice::whereBetween('invoice_date', [$from, $to])->sumBase('total');
                $expense[] = round((float) PurchaseInvoice::whereBetween('invoice_date', [$from, $to])->sumBase('total')
                    + (float) Expense::whereBetween('expense_date', [$from, $to])->sum('total_amount')
                    + (float) Payroll::where('status', '!=', 'draft')->whereBetween('payment_date', [$from, $to])->sum('net_total'), 2);
                $month->addMonth();
            }
            $range = [$yearStart->toDateString(), $yearEnd->toDateTimeString()];

            return [
                'labels' => $labels, 'revenue' => $revenue, 'expense' => $expense,
                'purchases' => (float) PurchaseInvoice::whereBetween('invoice_date', $range)->sumBase('total'),
                'expenses' => (float) Expense::whereBetween('expense_date', $range)->sum('total_amount'),
                'payroll' => (float) Payroll::where('status', '!=', 'draft')->whereBetween('payment_date', $range)->sum('net_total'),
                'pending' => Invoice::whereIn('payment_status', ['unpaid', 'partially_paid'])->count(),
                'overdue' => Invoice::whereIn('payment_status', ['unpaid', 'partially_paid'])->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count(),
            ];
        });

        // Active contracts: billed share of the contract value.
        $projects = $request->user()?->can('projects.projects.view') || $request->user()?->isCompanyOwner()
            ? Project::with('customer:id,name,company_name')->whereNotIn('status', ['closed', 'cancelled', 'completed'])->orderByDesc('id')->take(3)->get()
                ->map(function (Project $p) {
                    $billed = (float) DB::table('progress_billings')->where('project_id', $p->id)->whereIn('status', ['approved', 'invoiced'])->sum('current_value');
                    $value = (float) $p->contract_value;

                    return [
                        'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'customer' => $p->customer?->name ?? $p->customer?->company_name,
                        'value' => number_format($value, 2), 'billed_percent' => $value > 0 ? min(100, round($billed / $value * 100, 1)) : 0, 'status' => $p->status,
                    ];
                })->values()
            : collect();

        return Inertia::render('Dashboard', [
            'yearData' => $yearData,
            'projects' => $projects,
            'activeYearName' => $year?->name,
            // Setup steps of a new company, for its owner only.
            'onboarding' => fn () => $request->user()?->isCompanyOwner() && ($company = app(TenantManager::class)->getCompany())
                ? app(OnboardingChecklist::class)->forDashboard($company) : null,
            'kpis' => $kpis,
            'recentInvoices' => $recentInvoices,
            'topProducts' => $topProducts,
            'salesChart' => $salesChart,
            // Short cash outlook for users who can open the insights page.
            'cashOutlook' => fn () => $request->user()?->can('reports.insights.view')
                ? Cache::remember('insights:'.$tenantManager->getTenantId().':'.$scope.':outlook:'.now()->toDateString(), now()->addMinutes(10), function () {
                    $cash = app(Insights::class)->cashForecast(5);

                    return ['now' => $cash['opening'], 'in30' => $cash['weeks'][4]['balance'] ?? $cash['closing'], 'lowest' => $cash['lowest'], 'overdue' => $cash['overdue']['receivables']];
                })
                : null,
        ]);
    }
}

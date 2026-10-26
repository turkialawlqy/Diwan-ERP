<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
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
        $cacheKey = 'dashboard:'.$tenantManager->getTenantId().':'.$today->toDateString();
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

        return Inertia::render('Dashboard', [
            // Setup steps of a new company, for its owner only.
            'onboarding' => fn () => $request->user()?->isCompanyOwner() && ($company = app(TenantManager::class)->getCompany())
                ? app(OnboardingChecklist::class)->forDashboard($company) : null,
            'kpis' => $kpis,
            'recentInvoices' => $recentInvoices,
            'topProducts' => $topProducts,
            'salesChart' => $salesChart,
            // Short cash outlook for users who can open the insights page.
            'cashOutlook' => fn () => $request->user()?->can('reports.insights.view')
                ? Cache::remember('insights:'.$tenantManager->getTenantId().':outlook:'.now()->toDateString(), now()->addMinutes(10), function () {
                    $cash = app(Insights::class)->cashForecast(5);

                    return ['now' => $cash['opening'], 'in30' => $cash['weeks'][4]['balance'] ?? $cash['closing'], 'lowest' => $cash['lowest'], 'overdue' => $cash['overdue']['receivables']];
                })
                : null,
        ]);
    }
}

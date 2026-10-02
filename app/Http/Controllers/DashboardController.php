<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\CustomerPayment;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
        $todaySales = (float) Invoice::whereDate('invoice_date', $today)->sum('total');
        $monthSales = (float) Invoice::whereDate('invoice_date', '>=', $startOfMonth)->sum('total');
        $lastMonthSales = (float) Invoice::whereBetween('invoice_date', [$lastMonthStart, $lastMonthEnd])->sum('total');
        $monthSalesTrend = $lastMonthSales > 0
            ? round((($monthSales - $lastMonthSales) / $lastMonthSales) * 100, 1)
            : 0;

        $invoicesCount = Invoice::whereDate('invoice_date', '>=', $startOfMonth)->count();
        $lastMonthInvoicesCount = Invoice::whereBetween('invoice_date', [$lastMonthStart, $lastMonthEnd])->count();
        $invoicesCountTrend = $lastMonthInvoicesCount > 0
            ? round((($invoicesCount - $lastMonthInvoicesCount) / $lastMonthInvoicesCount) * 100, 1)
            : 0;

        $receivables = (float) Customer::where('status', 'active')->sum('current_balance');

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
            ->when($tenantManager->hasTenant(), function ($q) use ($tenantManager) {
                $q->where('invoices.company_id', $tenantManager->getTenantId());
            })
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
            $salesData[] = (float) Invoice::whereYear('invoice_date', $date->year)
                ->whereMonth('invoice_date', $date->month)
                ->sum('total');
        }

        $salesChart = [
            'months' => $months,
            'series' => [
                [
                    'name' => 'المبيعات (ر.س)',
                    'data' => $salesData,
                ],
            ],
        ];

        return Inertia::render('Dashboard', [
            'kpis' => $kpis,
            'recentInvoices' => $recentInvoices,
            'topProducts' => $topProducts,
            'salesChart' => $salesChart,
        ]);
    }
}

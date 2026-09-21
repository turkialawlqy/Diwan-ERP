<?php

namespace App\Http\Controllers;

use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display tenant ERP dashboard.
     */
    public function index(Request $request, TenantManager $tenantManager): Response
    {
        $company = $tenantManager->getTenant() ?? $request->user()?->company;

        // KPI metrics
        $kpis = [
            'today_sales' => '1,450.00',
            'month_sales' => '48,920.00',
            'month_sales_trend' => 14.2,
            'invoices_count' => 128,
            'invoices_count_trend' => 8.5,
            'receivables' => '12,350.00',
            'receivables_trend' => -3.4,
            'inventory_value' => '184,200.00',
            'low_stock_count' => 4,
        ];

        // Recent sales invoices matching the original system
        $recentInvoices = [
            [
                'id' => 1042,
                'number' => 'INV-001042',
                'customer_name' => 'شركة الوفاق للتجارة والمقاولات',
                'date' => '2026-09-20',
                'total' => '2,300.00',
                'status' => 'paid',
            ],
            [
                'id' => 1041,
                'number' => 'INV-001041',
                'customer_name' => 'مؤسسة البشائر الحديثة',
                'date' => '2026-09-20',
                'total' => '850.00',
                'status' => 'paid',
            ],
            [
                'id' => 1040,
                'number' => 'INV-001040',
                'customer_name' => 'عبدالله بن خالد الشهري',
                'date' => '2026-09-19',
                'total' => '4,120.00',
                'status' => 'partially_paid',
            ],
            [
                'id' => 1039,
                'number' => 'INV-001039',
                'customer_name' => 'شركة الرؤية للحلول الرقمية',
                'date' => '2026-09-19',
                'total' => '6,700.00',
                'status' => 'unpaid',
            ],
            [
                'id' => 1038,
                'number' => 'INV-001038',
                'customer_name' => 'مكتب المستشار الهندسي',
                'date' => '2026-09-18',
                'total' => '1,150.00',
                'status' => 'paid',
            ],
        ];

        // Top selling products
        $topProducts = [
            ['name' => 'لابتوب ديل انسبيرون 15', 'category' => 'أجهزة إلكترونية', 'sold_units' => 48, 'revenue' => '124,800.00'],
            ['name' => 'شاشة سامسونج 27 بوصة منحنية', 'category' => 'شاشات وملحقات', 'sold_units' => 35, 'revenue' => '31,500.00'],
            ['name' => 'طابعة إبسون L3250 واي فاي', 'category' => 'طابعات وماسحات', 'sold_units' => 29, 'revenue' => '20,300.00'],
            ['name' => 'لوحة مفاتيح وماوس لاسلكي لوجيتك', 'category' => 'ملحقات كمبيوتر', 'sold_units' => 74, 'revenue' => '11,100.00'],
        ];

        // Monthly sales chart series
        $salesChart = [
            'months' => ['أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر'],
            'series' => [
                [
                    'name' => 'المبيعات (ر.س)',
                    'data' => [32000, 41000, 38500, 52000, 47000, 48920],
                ],
                [
                    'name' => 'المشتريات (ر.س)',
                    'data' => [24000, 28000, 31000, 36000, 33000, 29500],
                ]
            ]
        ];

        return Inertia::render('Dashboard', [
            'kpis' => $kpis,
            'recentInvoices' => $recentInvoices,
            'topProducts' => $topProducts,
            'salesChart' => $salesChart,
        ]);
    }
}

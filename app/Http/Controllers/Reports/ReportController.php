<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Revenue;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function sales(Request $request): Response
    {
        $totalSales = Invoice::query()->sumBase('total');
        $totalCogs = PurchaseInvoice::query()->sumBase('total');

        $stats = [
            'total_sales' => $totalSales,
            'total_tax' => Invoice::query()->sumBase('tax_amount'),
            'invoices_count' => Invoice::count(),
            'total_payments_received' => CustomerPayment::where('status', '!=', 'cancelled')->sumBase('amount'),
            'gross_profit' => $totalSales - $totalCogs,
        ];

        return Inertia::render('Reports/Sales', [
            'stats' => $stats,
        ]);
    }

    public function inventory(Request $request): Response
    {
        // Aggregates computed in SQL (never load the whole catalogue in memory).
        $stockTotals = DB::table('inventory_stocks')
            ->where('company_id', app(TenantManager::class)->getTenantId())
            ->select('product_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('product_id');

        $base = Product::query()
            ->leftJoinSub($stockTotals, 'st', 'st.product_id', '=', 'products.id');

        $stats = [
            'total_products' => (clone $base)->count(),
            'total_stock_value' => (float) (clone $base)->sum(DB::raw('COALESCE(st.qty, 0) * COALESCE(products.purchase_price, 0)')),
            'low_stock_count' => (clone $base)
                ->whereNotNull('products.min_stock_alert')
                ->whereRaw('COALESCE(st.qty, 0) <= products.min_stock_alert')
                ->count(),
        ];

        $products = Product::with('category:id,name_ar,name_en')
            ->withStockTotal()
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name_ar', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")))
            ->orderBy('name_ar')
            ->paginate($this->perPage($request, 50))
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku ?: $p->barcode,
                'name_ar' => $p->name_ar,
                'category' => $p->category?->name_ar,
                'purchase_price' => (float) $p->purchase_price,
                'selling_price' => (float) $p->selling_price,
                'total_stock' => $p->total_stock,
                'stock_value' => $p->total_stock * (float) $p->purchase_price,
                'is_low' => $p->min_stock_alert !== null && $p->total_stock <= (float) $p->min_stock_alert,
            ]);

        return Inertia::render('Reports/Inventory', [
            'products' => $products,
            'stats' => $stats,
        ]);
    }

    public function vat(Request $request): Response
    {
        $salesSubtotal = Invoice::query()->sumBase('subtotal');
        $salesTax = Invoice::query()->sumBase('tax_amount');

        $purchasesSubtotal = PurchaseInvoice::query()->sumBase('subtotal');
        $purchasesTax = PurchaseInvoice::query()->sumBase('tax_amount');

        $expensesTax = Expense::sum('tax_amount');
        $revenuesTax = Revenue::sum('tax_amount');

        $totalOutputTax = $salesTax + $revenuesTax;
        $totalInputTax = $purchasesTax + $expensesTax;
        $netVatPayable = $totalOutputTax - $totalInputTax;

        return Inertia::render('Reports/Vat', [
            'vat' => [
                'sales_subtotal' => $salesSubtotal,
                'sales_tax' => $salesTax,
                'revenues_tax' => $revenuesTax,
                'total_output_tax' => $totalOutputTax,

                'purchases_subtotal' => $purchasesSubtotal,
                'purchases_tax' => $purchasesTax,
                'expenses_tax' => $expensesTax,
                'total_input_tax' => $totalInputTax,

                'net_vat_payable' => $netVatPayable,
            ],
        ]);
    }

    public function financial(Request $request): Response
    {
        $salesRevenue = Invoice::query()->sumBase('subtotal');
        $otherRevenue = Revenue::sum('amount');
        $totalRevenue = $salesRevenue + $otherRevenue;

        $cogs = PurchaseInvoice::query()->sumBase('subtotal');
        $grossProfit = $totalRevenue - $cogs;

        $operatingExpenses = Expense::sum('amount');
        $netProfit = $grossProfit - $operatingExpenses;

        return Inertia::render('Reports/Financial', [
            'incomeStatement' => [
                'sales_revenue' => $salesRevenue,
                'other_revenue' => $otherRevenue,
                'total_revenue' => $totalRevenue,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'operating_expenses' => $operatingExpenses,
                'net_profit' => $netProfit,
                'net_margin' => $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 1) : 0,
            ],
        ]);
    }
}

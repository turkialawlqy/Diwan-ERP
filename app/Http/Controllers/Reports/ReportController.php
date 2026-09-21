<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Revenue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function sales(Request $request): Response
    {
        $stats = [
            'total_sales' => Invoice::sum('total'),
            'total_tax' => Invoice::sum('tax_amount'),
            'invoices_count' => Invoice::count(),
            'total_payments_received' => CustomerPayment::sum('amount'),
            'gross_profit' => Invoice::sum('total') - (Invoice::sum('subtotal') * 0.65), // approximate 35% margin
        ];

        return Inertia::render('Reports/Sales', [
            'stats' => $stats,
        ]);
    }

    public function inventory(): Response
    {
        $products = Product::with(['category', 'stocks.warehouse'])->get();

        $stats = [
            'total_products' => $products->count(),
            'total_stock_value' => $products->sum(function ($p) {
                return $p->cost_price * $p->stocks->sum('quantity');
            }),
            'low_stock_count' => $products->filter(function ($p) {
                return $p->stocks->sum('quantity') <= $p->alert_quantity;
            })->count(),
        ];

        return Inertia::render('Reports/Inventory', [
            'products' => $products,
            'stats' => $stats,
        ]);
    }

    public function vat(Request $request): Response
    {
        $salesSubtotal = Invoice::sum('subtotal');
        $salesTax = Invoice::sum('tax_amount');

        $purchasesSubtotal = PurchaseInvoice::sum('subtotal');
        $purchasesTax = PurchaseInvoice::sum('tax_amount');

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
            ]
        ]);
    }

    public function financial(Request $request): Response
    {
        $salesRevenue = Invoice::sum('subtotal');
        $otherRevenue = Revenue::sum('amount');
        $totalRevenue = $salesRevenue + $otherRevenue;

        $cogs = PurchaseInvoice::sum('subtotal');
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
            ]
        ]);
    }
}

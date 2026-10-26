<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Revenue;
use App\Services\Accounting\AccountingReports;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Period for the summary reports (default: this year to date). Figures come
     * from the ledger, so they agree with the financial statements: net of
     * credit notes, cost of sales from the stock ledger, posted documents only.
     */
    protected function period(Request $request): array
    {
        $validated = $request->validate(['start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date']);
        $from = Carbon::parse($validated['start_date'] ?? now()->startOfYear()->toDateString())->startOfDay();
        $to = Carbon::parse($validated['end_date'] ?? now()->toDateString())->endOfDay();

        return [$from, $to, ['start_date' => $from->toDateString(), 'end_date' => $to->toDateString()]];
    }

    public function sales(Request $request, AccountingReports $reports): Response
    {
        [$from, $to, $filters] = $this->period($request);
        $income = $reports->incomeStatement($from, $to);
        $vat = $reports->vatReturn($from, $to);

        $stats = [
            'total_sales' => $income['operating_revenue'],
            'total_tax' => $vat['output_vat'],
            'invoices_count' => Invoice::whereBetween('invoice_date', [$filters['start_date'], $filters['end_date'].' 23:59:59'])->count(),
            'total_payments_received' => CustomerPayment::where('status', '!=', 'cancelled')->whereBetween('payment_date', [$filters['start_date'], $filters['end_date'].' 23:59:59'])->sumBase('amount'),
            'gross_profit' => $income['gross_profit'],
        ];

        // A user limited to some branches sees their branches' invoices, not the company-wide ledger.
        if (($allowed = $request->user()?->allowedBranchIds()) !== null) {
            $invoices = Invoice::whereIn('branch_id', $allowed)->whereBetween('invoice_date', [$filters['start_date'], $filters['end_date'].' 23:59:59']);
            $stats = [
                'total_sales' => (clone $invoices)->sumBase('subtotal'),
                'total_tax' => (clone $invoices)->sumBase('tax_amount'),
                'invoices_count' => (clone $invoices)->count(),
                'total_payments_received' => (clone $invoices)->sumBase('paid_amount'),
                'gross_profit' => null,
            ];
        }

        return Inertia::render('Reports/Sales', [
            'stats' => $stats,
            'filters' => $filters,
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
            'total_stock_value' => (float) (clone $base)->sum(DB::raw('COALESCE(st.qty, 0) * COALESCE(NULLIF(products.average_cost, 0), products.purchase_price, 0)')),
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
                'stock_value' => $p->total_stock * (float) ((float) $p->average_cost ?: $p->purchase_price),
                'is_low' => $p->min_stock_alert !== null && $p->total_stock <= (float) $p->min_stock_alert,
            ]);

        return Inertia::render('Reports/Inventory', [
            'products' => $products,
            'stats' => $stats,
        ]);
    }

    public function vat(Request $request, AccountingReports $reports): Response
    {
        [$from, $to, $filters] = $this->period($request);
        $return = $reports->vatReturn($from, $to);
        $boxes = collect($return['boxes'])->keyBy('no');
        $range = [$filters['start_date'], $filters['end_date'].' 23:59:59'];
        $revenuesTax = round((float) Revenue::whereBetween('revenue_date', $range)->sum('tax_amount'), 2);
        $expensesTax = round((float) Expense::whereBetween('expense_date', $range)->sum('tax_amount'), 2);

        return Inertia::render('Reports/Vat', [
            'vat' => [
                'sales_subtotal' => round($boxes[6]['amount'] + $boxes[6]['adjustment'], 2),
                'sales_tax' => round($return['output_vat'] - $revenuesTax, 2),
                'revenues_tax' => $revenuesTax,
                'total_output_tax' => $return['output_vat'],

                'purchases_subtotal' => round($boxes[12]['amount'] + $boxes[12]['adjustment'], 2),
                'purchases_tax' => round($return['input_vat'] - $expensesTax, 2),
                'expenses_tax' => $expensesTax,
                'total_input_tax' => $return['input_vat'],

                'net_vat_payable' => $return['net_vat'],
            ],
            'filters' => $filters,
        ]);
    }

    public function financial(Request $request, AccountingReports $reports): Response
    {
        [$from, $to, $filters] = $this->period($request);
        $income = $reports->incomeStatement($from, $to);
        $revenue = (float) $income['total_revenue'];

        return Inertia::render('Reports/Financial', [
            'incomeStatement' => [
                'sales_revenue' => $income['operating_revenue'],
                'other_revenue' => round($revenue - $income['operating_revenue'], 2),
                'total_revenue' => $income['total_revenue'],
                'cogs' => $income['cost_of_sales'],
                'gross_profit' => $income['gross_profit'],
                'operating_expenses' => round($income['total_expense'] - $income['cost_of_sales'], 2),
                'net_profit' => $income['net_profit'],
                'net_margin' => $revenue > 0 ? round(($income['net_profit'] / $revenue) * 100, 1) : 0,
            ],
            'filters' => $filters,
        ]);
    }
}

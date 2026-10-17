<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventory reports read from the stock movements ledger with SQL
 * aggregates and pagination (never the whole catalogue in memory).
 */
class InventoryReportController extends Controller
{
    protected const MOVEMENT_LABELS = [
        'opening' => 'رصيد افتتاحي', 'purchase' => 'مشتريات', 'purchase_return' => 'مرتجع مشتريات', 'sale' => 'مبيعات',
        'sale_return' => 'مرتجع مبيعات', 'transfer_out' => 'تحويل صادر', 'transfer_in' => 'تحويل وارد', 'adjustment' => 'تسوية',
        'count' => 'جرد', 'damage' => 'إتلاف', 'assembly_in' => 'إنتاج', 'assembly_out' => 'صرف للإنتاج', 'project_issue' => 'صرف لمشروع', 'project_return' => 'مرتجع من مشروع', 'maintenance_issue' => 'صرف لصيانة معدة', 'revaluation' => 'تسوية قيمة (تكاليف إضافية / فرق سعر)',
        'delivery' => 'إذن صرف', 'receipt' => 'إذن استلام', 'delivery_cancel' => 'إلغاء إذن صرف', 'receipt_cancel' => 'إلغاء إذن استلام',
    ];

    /**
     * Item card: movements of one product with running quantity.
     */
    public function itemCard(Request $request): Response
    {
        $filters = $request->validate([
            'product_id' => 'nullable|tenant_exists:products,id',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $from = Carbon::parse($filters['start_date'] ?? now()->startOfYear()->toDateString());
        $to = Carbon::parse($filters['end_date'] ?? now()->toDateString());
        $product = isset($filters['product_id']) ? Product::find($filters['product_id']) : null;

        $data = null;
        if ($product) {
            $base = StockMovement::where('product_id', $product->id)
                ->when($filters['warehouse_id'] ?? null, fn ($q, $w) => $q->where('warehouse_id', $w));

            $opening = (clone $base)->where('date', '<', $from->toDateString())->selectRaw('COALESCE(SUM(quantity),0) q, COALESCE(SUM(total_cost),0) v')->first();

            $movements = (clone $base)
                ->with('warehouse:id,name_ar', 'batch:id,batch_number')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
                ->orderBy('date')->orderBy('id')
                ->paginate($this->perPage($request, 50, 200))
                ->withQueryString();

            $before = 0.0;
            if ($movements->currentPage() > 1 && $movements->count()) {
                $first = $movements->first();
                $before = (float) (clone $base)->whereBetween('date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
                    ->where(fn ($q) => $q->where('date', '<', $first->date)->orWhere(fn ($q) => $q->where('date', $first->date)->where('id', '<', $first->id)))
                    ->sum('quantity');
            }

            $running = (float) $opening->q + $before;
            $movements->getCollection()->transform(function ($m) use (&$running) {
                $running += (float) $m->quantity;

                return [
                    'id' => $m->id,
                    'date' => $m->date->toDateString(),
                    'type' => self::MOVEMENT_LABELS[$m->type] ?? $m->type,
                    'warehouse' => $m->warehouse?->name_ar,
                    'batch' => $m->batch?->batch_number,
                    'reference' => $m->reference,
                    'in' => $m->quantity > 0 ? (float) $m->quantity : 0,
                    'out' => $m->quantity < 0 ? -(float) $m->quantity : 0,
                    'unit_cost' => (float) $m->unit_cost,
                    'balance' => round($running, 4),
                ];
            });

            $data = ['opening_quantity' => (float) $opening->q, 'opening_value' => (float) $opening->v, 'movements' => $movements];
        }

        return Inertia::render('Inventory/Reports/ItemCard', [
            'product' => $product?->only(['id', 'name_ar', 'sku', 'average_cost']),
            'data' => $data,
            'warehouses' => Warehouse::get(['id', 'name_ar']),
            'filters' => array_merge($filters, ['start_date' => $from->toDateString(), 'end_date' => $to->toDateString()]),
        ]);
    }

    /**
     * Stock balances and valuation, optionally as of a past date (from movements).
     */
    public function valuation(Request $request): Response
    {
        $filters = $request->validate([
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'category_id' => 'nullable|tenant_exists:categories,id',
            'as_of' => 'nullable|date',
            'search' => 'nullable|string|max:100',
        ]);

        $companyId = app(TenantManager::class)->getTenantId();
        $asOf = isset($filters['as_of']) ? Carbon::parse($filters['as_of']) : null;

        $quantities = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->when($filters['warehouse_id'] ?? null, fn ($q, $w) => $q->where('warehouse_id', $w))
            ->when($asOf, fn ($q) => $q->where('date', '<=', $asOf->toDateString().' 23:59:59'))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as qty, SUM(total_cost) as value');

        $query = Product::query()
            ->joinSub($quantities, 'q', 'q.product_id', '=', 'products.id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('q.qty', '!=', 0)
            ->when($filters['category_id'] ?? null, fn ($q, $c) => $q->where('products.category_id', $c))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('products.name_ar', 'like', "%{$s}%")->orWhere('products.sku', 'like', "{$s}%")));

        $totals = (clone $query)->selectRaw('COUNT(*) as items, COALESCE(SUM(q.qty),0) as qty, COALESCE(SUM(q.value),0) as value')->first();

        $rows = $query
            ->select('products.id', 'products.name_ar', 'products.sku', 'products.average_cost', 'products.reorder_level', 'categories.name_ar as category', 'q.qty', 'q.value')
            ->orderBy('products.name_ar')
            ->paginate($this->perPage($request, 50, 200))
            ->withQueryString();

        return Inertia::render('Inventory/Reports/Valuation', [
            'rows' => $rows,
            'totals' => ['items' => (int) $totals->items, 'qty' => (float) $totals->qty, 'value' => round((float) $totals->value, 2)],
            'warehouses' => Warehouse::get(['id', 'name_ar']),
            'categories' => Category::get(['id', 'name_ar']),
            'filters' => $filters,
        ]);
    }

    /**
     * Products at or below their reorder level, with a suggested order quantity.
     */
    public function reorder(Request $request): Response
    {
        $companyId = app(TenantManager::class)->getTenantId();

        $onHand = DB::table('inventory_stocks')->where('company_id', $companyId)->groupBy('product_id')->selectRaw('product_id, SUM(quantity) as qty');

        $rows = Product::query()
            ->leftJoinSub($onHand, 's', 's.product_id', '=', 'products.id')
            ->where('products.track_inventory', true)
            ->where('products.is_active', true)
            ->whereRaw('COALESCE(s.qty, 0) <= COALESCE(products.reorder_level, products.min_stock_alert, 0)')
            ->whereRaw('COALESCE(products.reorder_level, products.min_stock_alert, 0) > 0')
            ->select('products.id', 'products.name_ar', 'products.sku', 'products.reorder_level', 'products.min_stock_alert', 'products.reorder_quantity', 'products.purchase_price', DB::raw('COALESCE(s.qty, 0) as on_hand'))
            ->orderBy('products.name_ar')
            ->paginate($this->perPage($request, 50, 200))
            ->through(function ($p) {
                $level = (float) ($p->reorder_level ?? $p->min_stock_alert);
                $suggested = (float) ($p->reorder_quantity ?: max($level * 2 - (float) $p->on_hand, $level));

                return [
                    'id' => $p->id, 'name_ar' => $p->name_ar, 'sku' => $p->sku, 'on_hand' => (float) $p->on_hand,
                    'reorder_level' => $level, 'suggested' => round($suggested, 4), 'purchase_price' => (float) $p->purchase_price,
                ];
            });

        return Inertia::render('Inventory/Reports/Reorder', ['rows' => $rows]);
    }

    /**
     * Stock items without any outgoing movement for N days.
     */
    public function slowMoving(Request $request): Response
    {
        $days = (int) ($request->validate(['days' => 'nullable|integer|min:1|max:3650'])['days'] ?? 90);
        $companyId = app(TenantManager::class)->getTenantId();
        $since = now()->subDays($days)->toDateString();

        $lastOut = DB::table('stock_movements')->where('company_id', $companyId)->where('quantity', '<', 0)->groupBy('product_id')->selectRaw('product_id, MAX(date) as last_out');
        $onHand = DB::table('inventory_stocks')->where('company_id', $companyId)->groupBy('product_id')->selectRaw('product_id, SUM(quantity) as qty');

        $rows = Product::query()
            ->joinSub($onHand, 's', 's.product_id', '=', 'products.id')
            ->leftJoinSub($lastOut, 'o', 'o.product_id', '=', 'products.id')
            ->where('s.qty', '>', 0)
            ->where(fn ($q) => $q->whereNull('o.last_out')->orWhere('o.last_out', '<', $since))
            ->select('products.id', 'products.name_ar', 'products.sku', 'products.average_cost', 's.qty', 'o.last_out')
            ->orderBy('o.last_out')
            ->paginate($this->perPage($request, 50, 200))
            ->withQueryString()
            ->through(fn ($p) => [
                'id' => $p->id, 'name_ar' => $p->name_ar, 'sku' => $p->sku, 'qty' => (float) $p->qty,
                'value' => round((float) $p->qty * (float) $p->average_cost, 2), 'last_out' => $p->last_out ? substr($p->last_out, 0, 10) : null,
            ]);

        return Inertia::render('Inventory/Reports/SlowMoving', ['rows' => $rows, 'days' => $days]);
    }

    /**
     * Batches expiring within N days (or already expired) that still have stock.
     */
    public function expiry(Request $request): Response
    {
        $days = (int) ($request->validate(['days' => 'nullable|integer|min:0|max:3650'])['days'] ?? 60);

        $rows = ProductBatch::with(['product:id,name_ar,sku', 'warehouse:id,name_ar'])
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays($days)->toDateString())
            ->orderBy('expiry_date')
            ->paginate($this->perPage($request, 50, 200))
            ->withQueryString();

        return Inertia::render('Inventory/Reports/Expiry', ['rows' => $rows, 'days' => $days, 'today' => now()->toDateString()]);
    }
}

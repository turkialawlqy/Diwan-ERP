<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\Warehouse;
use App\Services\Accounting\DocumentPoster;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock count documents: snapshot system quantities, enter counted figures
 * (in as many sessions as needed), then approve to post the differences.
 */
class StockCountController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Inventory/Counts/Index', [
            'counts' => StockCount::with('warehouse:id,name_ar')->withCount('items')->orderByDesc('id')->paginate($this->perPage($request)),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            'count_date' => 'required|date',
            'count_type' => 'required|in:full,partial',
            'category_id' => 'nullable|tenant_exists:categories,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if (StockCount::where('warehouse_id', $validated['warehouse_id'])->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => 'يوجد جرد مفتوح لهذا المستودع. اعتمده أو ألغه أولاً.']);
        }

        $count = DB::transaction(function () use ($validated) {
            $count = StockCount::create([
                'count_number' => $this->nextNumber('stock_count', $validated['count_date']),
                'warehouse_id' => $validated['warehouse_id'],
                'count_date' => $validated['count_date'],
                'count_type' => $validated['count_type'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'draft',
            ]);

            // Full counts snapshot every stock item of the warehouse; partial
            // (spot) counts start empty and lines are added as products are counted.
            if ($validated['count_type'] === 'full') {
                Product::query()
                    ->where('track_inventory', true)
                    ->where('is_kit', false)
                    ->when($validated['category_id'] ?? null, fn ($q, $c) => $q->where('category_id', $c))
                    ->select(['id', 'average_cost'])
                    ->chunkById(1000, function ($products) use ($count, $validated) {
                        $quantities = InventoryStock::where('warehouse_id', $validated['warehouse_id'])
                            ->whereIn('product_id', $products->pluck('id'))
                            ->pluck('quantity', 'product_id');
                        $now = now();
                        StockCountItem::insert($products->map(fn ($p) => [
                            'company_id' => $count->company_id,
                            'stock_count_id' => $count->id,
                            'product_id' => $p->id,
                            'system_quantity' => (float) ($quantities[$p->id] ?? 0),
                            'counted_quantity' => null,
                            'unit_cost' => (float) $p->average_cost,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all());
                    });
            }

            return $count;
        });

        return redirect()->route('inventory.counts.show', $count->id);
    }

    public function show(Request $request, StockCount $count): Response
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'only' => 'nullable|in:uncounted,differences']);

        $items = StockCountItem::with('product:id,name_ar,sku,barcode')
            ->where('stock_count_id', $count->id)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->whereHas('product', fn ($p) => $p->where('name_ar', 'like', "%{$s}%")->orWhere('sku', 'like', "{$s}%")->orWhere('barcode', $s)))
            ->when(($filters['only'] ?? null) === 'uncounted', fn ($q) => $q->whereNull('counted_quantity'))
            ->when(($filters['only'] ?? null) === 'differences', fn ($q) => $q->whereNotNull('counted_quantity')->whereColumn('counted_quantity', '!=', 'system_quantity'))
            ->orderBy('id')
            ->paginate($this->perPage($request, 50))
            ->withQueryString();

        $summary = StockCountItem::where('stock_count_id', $count->id)
            ->selectRaw('COUNT(*) as lines, SUM(CASE WHEN counted_quantity IS NULL THEN 1 ELSE 0 END) as uncounted,
                COALESCE(SUM(CASE WHEN counted_quantity IS NOT NULL THEN (counted_quantity - system_quantity) * unit_cost ELSE 0 END),0) as value_difference')
            ->first();

        return Inertia::render('Inventory/Counts/Show', [
            'count' => $count->load('warehouse:id,name_ar'),
            'items' => $items,
            'summary' => $summary,
            'filters' => $filters,
        ]);
    }

    /**
     * Save counted quantities (and add products for spot counts).
     */
    public function update(Request $request, StockCount $count)
    {
        $this->assertDraft($count);

        $validated = $request->validate([
            'lines' => 'required|array|max:1000',
            'lines.*.product_id' => 'required|tenant_exists:products,id',
            'lines.*.counted_quantity' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, $count) {
            foreach ($validated['lines'] as $line) {
                $item = StockCountItem::firstOrNew(['stock_count_id' => $count->id, 'product_id' => $line['product_id']]);
                if (! $item->exists) {
                    $item->system_quantity = (float) InventoryStock::where('warehouse_id', $count->warehouse_id)->where('product_id', $line['product_id'])->value('quantity');
                    $item->unit_cost = (float) Product::find($line['product_id'])->average_cost;
                }
                $item->counted_quantity = $line['counted_quantity'];
                $item->save();
            }
        });

        return back()->with('success', 'تم حفظ الكميات المجرودة');
    }

    /**
     * Approve: set every counted line to its counted quantity and post the
     * net surplus / shortage.
     */
    public function approve(StockCount $count, StockService $stock, DocumentPoster $poster)
    {
        $this->assertDraft($count);

        DB::transaction(function () use ($count, $stock, $poster) {
            $value = 0.0;

            StockCountItem::where('stock_count_id', $count->id)
                ->whereNotNull('counted_quantity')
                ->orderBy('id')
                ->chunkById(200, function ($items) use ($count, $stock, &$value) {
                    foreach ($items as $item) {
                        $value += $stock->adjustTo($item->product_id, $count->warehouse_id, (float) $item->counted_quantity, [
                            'type' => 'count',
                            'date' => $count->count_date,
                            'source' => $count,
                            'reference' => $count->count_number,
                        ]);
                    }
                });

            $poster->stockAdjustment($count, $value, $count->count_date, "فروقات الجرد رقم {$count->count_number}");

            $count->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });

        return back()->with('success', 'تم اعتماد الجرد وتسوية الأرصدة وترحيل الفروقات');
    }

    public function cancel(StockCount $count)
    {
        $this->assertDraft($count);
        $count->update(['status' => 'cancelled']);

        return back()->with('success', 'تم إلغاء الجرد');
    }

    protected function assertDraft(StockCount $count): void
    {
        if ($count->status !== 'draft') {
            throw ValidationException::withMessages(['count' => 'الجرد معتمد أو ملغى ولا يمكن تعديله.']);
        }
    }
}

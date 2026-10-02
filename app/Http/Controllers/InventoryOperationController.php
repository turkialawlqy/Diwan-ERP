<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Accounting\DocumentPoster;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventoryOperationController extends Controller
{
    public function index(): Response
    {
        $stocks = InventoryStock::with(['product', 'warehouse'])
            ->latest()
            ->paginate(20);

        $products = Product::where('is_active', true)->get(['id', 'name_ar', 'name_en', 'sku']);
        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name_ar', 'name_en', 'code']);

        $totalUnits = InventoryStock::sum('quantity');
        $warehousesCount = Warehouse::where('is_active', true)->count();
        $stockedProductsCount = InventoryStock::distinct('product_id')->count('product_id');

        return Inertia::render('Inventory/Operations/Index', [
            'stocks' => $stocks,
            'products' => $products,
            'warehouses' => $warehouses,
            'stats' => [
                'total_units' => number_format($totalUnits, 2),
                'warehouses_count' => $warehousesCount,
                'stocked_products_count' => $stockedProductsCount,
            ],
        ]);
    }

    /**
     * Immediate transfer between warehouses (same site). For transfers that
     * travel, use stock transfer documents (send / receive).
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|tenant_exists:products,id',
            'from_warehouse_id' => 'required|tenant_exists:warehouses,id|different:to_warehouse_id',
            'to_warehouse_id' => 'required|tenant_exists:warehouses,id',
            'quantity' => 'required|numeric|min:0.0001',
            'batch_id' => 'nullable|integer',
            'serials' => 'nullable|array',
            'notes' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($validated) {
            app(StockService::class)->transfer(
                (int) $validated['product_id'],
                (int) $validated['from_warehouse_id'],
                (int) $validated['to_warehouse_id'],
                (float) $validated['quantity'],
                [
                    'date' => now(),
                    'notes' => $validated['notes'] ?? null,
                    'batch_id' => $validated['batch_id'] ?? null,
                    'serials' => $validated['serials'] ?? [],
                    'source_type' => 'manual_transfer',
                ],
            );
        });

        return redirect()->route('inventory.operations.index')
            ->with('success', 'تم تحويل المخزون بين المستودعات بنجاح');
    }

    /**
     * Manual adjustment (add / subtract / set / damage), valued at cost and posted.
     */
    public function adjust(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|tenant_exists:products,id',
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            'type' => 'required|in:add,subtract,set,damage',
            'quantity' => 'required|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'serials' => 'nullable|array',
            'reason' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($validated) {
            $stock = app(StockService::class);
            $product = Product::findOrFail($validated['product_id']);
            $qty = (float) $validated['quantity'];
            $context = [
                'product_id' => $product->id,
                'warehouse_id' => (int) $validated['warehouse_id'],
                'date' => now(),
                'notes' => $validated['reason'] ?? null,
                'source_type' => 'stock_adjustment',
                'serials' => $validated['serials'] ?? [],
            ];

            $value = match ($validated['type']) {
                'add' => $qty > 0 ? $stock->receive(array_merge($context, [
                    'quantity' => $qty,
                    'type' => 'adjustment',
                    'unit_cost' => $validated['unit_cost'] ?? (float) $product->average_cost,
                    'batch_number' => $validated['batch_number'] ?? null,
                    'expiry_date' => $validated['expiry_date'] ?? null,
                ]))->total_cost : 0,
                'subtract', 'damage' => $qty > 0 ? -$stock->issue(array_merge($context, [
                    'quantity' => $qty,
                    'type' => $validated['type'] === 'damage' ? 'damage' : 'adjustment',
                ])) : 0,
                'set' => $stock->adjustTo($product->id, (int) $validated['warehouse_id'], $qty, array_merge($context, ['type' => 'adjustment'])),
            };

            app(DocumentPoster::class)->stockAdjustment(
                'stock_adjustment',
                (float) $value,
                now(),
                ($validated['type'] === 'damage' ? 'إتلاف مخزون: ' : 'تسوية مخزون: ').$product->name_ar.' - '.($validated['reason'] ?? 'تعديل يدوي'),
            );
        });

        return redirect()->route('inventory.operations.index')
            ->with('success', 'تم تعديل كمية المخزون وترحيل قيد التسوية بنجاح');
    }
}

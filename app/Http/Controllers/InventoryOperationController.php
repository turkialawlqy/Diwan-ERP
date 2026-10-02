<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\TenantManager;
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
     * Transfer stock between warehouses.
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'from_warehouse_id' => 'required|exists:warehouses,id|different:to_warehouse_id',
            'to_warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
        ]);

        $companyId = app(TenantManager::class)->getTenantId() ?? auth()->user()?->company_id;

        DB::transaction(function () use ($validated, $companyId) {
            $qty = (float) $validated['quantity'];

            // 1. Check source stock (locked to prevent a concurrent transfer/sale
            // from reading the same stale quantity and overselling it).
            $sourceStock = InventoryStock::where('product_id', $validated['product_id'])
                ->where('warehouse_id', $validated['from_warehouse_id'])
                ->lockForUpdate()
                ->first();

            if (!$sourceStock || $sourceStock->quantity < $qty) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'quantity' => 'الكمية المتوفرة في المستودع المصدر غير كافية لإتمام التحويل.',
                ]);
            }

            // Deduct from source
            $sourceStock->decrement('quantity', $qty);

            // 2. Add to destination stock
            $targetStock = InventoryStock::firstOrCreate(
                [
                    'company_id' => $companyId,
                    'product_id' => $validated['product_id'],
                    'warehouse_id' => $validated['to_warehouse_id'],
                ],
                ['quantity' => 0]
            );

            $targetStock->increment('quantity', $qty);
        });

        return redirect()->route('inventory.operations.index')
            ->with('success', 'تم تحويل المخزون بين المستودعات بنجاح');
    }

    /**
     * Adjust stock directly (inventory manual adjustment).
     */
    public function adjust(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'type' => 'required|in:add,subtract,set',
            'quantity' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $companyId = app(TenantManager::class)->getTenantId() ?? auth()->user()?->company_id;

        DB::transaction(function () use ($validated, $companyId) {
            $stock = InventoryStock::firstOrCreate(
                [
                    'company_id' => $companyId,
                    'product_id' => $validated['product_id'],
                    'warehouse_id' => $validated['warehouse_id'],
                ],
                ['quantity' => 0]
            );

            // Lock the row before re-reading quantity so a concurrent adjustment,
            // transfer, or sale can't decrement the same stale value.
            $stock = InventoryStock::where('id', $stock->id)->lockForUpdate()->first();

            $qty = (float) $validated['quantity'];

            if ($validated['type'] === 'add') {
                $stock->increment('quantity', $qty);
            } elseif ($validated['type'] === 'subtract') {
                if ($stock->quantity < $qty) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'quantity' => 'الكمية المطلوب خصمها أكبر من الكمية المتوفرة في المخزون.',
                    ]);
                }
                $stock->decrement('quantity', $qty);
            } else {
                $stock->update(['quantity' => $qty]);
            }
        });

        return redirect()->route('inventory.operations.index')
            ->with('success', 'تم تعديل كمية المخزون بنجاح');
    }
}

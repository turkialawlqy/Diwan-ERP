<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use App\Models\Warehouse;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockCountController extends Controller
{
    public function index(Request $request): Response
    {
        $warehouseId = $request->query('warehouse_id');
        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name_ar', 'name_en', 'code']);

        $selectedWarehouse = $warehouseId
            ? $warehouses->firstWhere('id', $warehouseId)
            : $warehouses->first();

        $stocks = [];
        if ($selectedWarehouse) {
            $stocks = InventoryStock::with('product')
                ->where('warehouse_id', $selectedWarehouse->id)
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'product_id' => $s->product_id,
                    'product_name' => $s->product?->name_ar ?? '—',
                    'sku' => $s->product?->sku ?? '—',
                    'system_qty' => (float) $s->quantity,
                    'physical_qty' => (float) $s->quantity,
                    'difference' => 0,
                ]);
        }

        return Inertia::render('Inventory/Counts/Index', [
            'warehouses' => $warehouses,
            'selectedWarehouseId' => $selectedWarehouse?->id,
            'stocks' => $stocks,
        ]);
    }

    public function reconcile(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            'counts' => 'required|array|min:1',
            'counts.*.product_id' => 'required|tenant_exists:products,id',
            'counts.*.physical_qty' => 'required|numeric|min:0',
        ]);

        $companyId = app(TenantManager::class)->getTenantId() ?? auth()->user()?->company_id;

        DB::transaction(function () use ($validated, $companyId) {
            foreach ($validated['counts'] as $countItem) {
                $stock = InventoryStock::firstOrCreate(
                    [
                        'company_id' => $companyId,
                        'product_id' => $countItem['product_id'],
                        'warehouse_id' => $validated['warehouse_id'],
                    ],
                    ['quantity' => 0]
                );

                $stock->update(['quantity' => (float) $countItem['physical_qty']]);
            }
        });

        return redirect()->route('inventory.counts.index', ['warehouse_id' => $validated['warehouse_id']])
            ->with('success', 'تم اعتماد الجرد وتحديث أرصدة المخزون بنجاح');
    }
}

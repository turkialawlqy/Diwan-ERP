<?php

namespace App\Http\Controllers;

use App\Models\AssemblyOrder;
use App\Models\Bom;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Light manufacturing: bills of materials and assembly orders that consume
 * components and produce finished goods at the components' cost.
 */
class AssemblyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Inventory/Assembly/Index', [
            'boms' => Bom::with(['product:id,name_ar,sku', 'items.component:id,name_ar,sku,average_cost'])->orderByDesc('id')->get(),
            'orders' => AssemblyOrder::with(['bom.product:id,name_ar', 'warehouse:id,name_ar'])->orderByDesc('id')->paginate($this->perPage($request)),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
        ]);
    }

    public function storeBom(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|tenant_exists:products,id',
            'name' => 'required|string|max:255',
            'output_quantity' => 'required|numeric|min:0.0001',
            'items' => 'required|array|min:1|max:200',
            'items.*.component_id' => 'required|tenant_exists:products,id|different:product_id',
            'items.*.quantity' => 'required|numeric|min:0.0001',
        ]);

        DB::transaction(function () use ($validated) {
            $bom = Bom::create(collect($validated)->except('items')->all() + ['is_active' => true]);
            foreach ($validated['items'] as $item) {
                $bom->items()->create($item);
            }
        });

        return back()->with('success', 'تم حفظ قائمة المواد');
    }

    public function storeOrder(Request $request, StockService $stock)
    {
        $validated = $request->validate([
            'bom_id' => 'required|tenant_exists:boms,id',
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            'quantity' => 'required|numeric|min:0.0001',
            'date' => 'required|date',
        ]);

        $bom = Bom::with('items')->findOrFail($validated['bom_id']);
        if (! $bom->is_active) {
            throw ValidationException::withMessages(['bom_id' => 'قائمة المواد غير نشطة.']);
        }

        DB::transaction(function () use ($validated, $bom, $stock) {
            $order = AssemblyOrder::create([
                'order_number' => $this->nextNumber('assembly_order', $validated['date']),
                'bom_id' => $bom->id,
                'warehouse_id' => $validated['warehouse_id'],
                'quantity' => $validated['quantity'],
                'date' => $validated['date'],
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            $runs = (float) $validated['quantity'] / (float) $bom->output_quantity;
            $cost = 0.0;

            foreach ($bom->items as $item) {
                $cost += $stock->issue([
                    'product_id' => $item->component_id,
                    'warehouse_id' => $order->warehouse_id,
                    'quantity' => round((float) $item->quantity * $runs, 4),
                    'type' => 'assembly_out',
                    'date' => $order->date,
                    'source' => $order,
                    'reference' => $order->order_number,
                ]);
            }

            // Finished goods carry exactly the consumed cost: inventory value is
            // unchanged, so no journal entry is needed.
            $stock->receive([
                'product_id' => $bom->product_id,
                'warehouse_id' => $order->warehouse_id,
                'quantity' => (float) $order->quantity,
                'unit_cost' => $cost / (float) $order->quantity,
                'type' => 'assembly_in',
                'date' => $order->date,
                'source' => $order,
                'reference' => $order->order_number,
            ]);

            $order->update(['status' => 'completed', 'total_cost' => round($cost, 2)]);
        });

        return back()->with('success', 'تم تنفيذ أمر التجميع');
    }
}

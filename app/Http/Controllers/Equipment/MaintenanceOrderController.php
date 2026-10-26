<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\InventoryStock;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceOrderItem;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Equipment\MaintenanceService;
use App\Services\Equipment\MaintenanceTracker;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MaintenanceOrderController extends Controller
{
    public function __construct(protected MaintenanceTracker $tracker) {}

    public function index(Request $request)
    {
        $orders = MaintenanceOrder::with('equipment:id,code,name')->withCount('items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('equipment'), fn ($q) => $q->where('equipment_id', $request->integer('equipment')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.$request->q.'%')
                ->orWhereHas('equipment', fn ($e) => $e->where('name', 'like', '%'.$request->q.'%')->orWhere('code', 'like', $request->q.'%'))))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")->latest('date')->latest('id')
            ->paginate($this->perPage($request));

        return Inertia::render('Equipment/Maintenance/Index', [
            'orders' => $orders,
            'filters' => $request->only('status', 'q', 'equipment'),
            'equipment' => Equipment::where('status', '!=', 'out_of_service')->orderBy('code')->get(['id', 'code', 'name', 'meter_type', 'current_hours', 'current_km']),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
            'prefill' => $request->filled('equipment_id') ? $request->integer('equipment_id') : null,
        ]);
    }

    /** What is overdue / coming up, what is in the workshop, what parts are short. */
    public function dashboard()
    {
        $due = $this->tracker->dueList();
        $rows = $due->map(fn ($r) => [
            'schedule_id' => $r['schedule']->id, 'name' => $r['schedule']->name, 'product' => $r['schedule']->product?->only(['id', 'name_ar', 'sku']),
            'quantity' => $r['schedule']->default_quantity, 'equipment' => $r['equipment']->only(['id', 'code', 'name', 'status', 'current_hours', 'current_km', 'meter_type']),
            'status' => $r['status'],
        ])->values();

        $needed = $due->filter(fn ($r) => $r['schedule']->product_id)->groupBy(fn ($r) => $r['schedule']->product_id);
        $stock = InventoryStock::whereIn('product_id', $needed->keys())->groupBy('product_id')->selectRaw('product_id, SUM(quantity) q')->pluck('q', 'product_id');
        $parts = $needed->map(fn ($g, $productId) => [
            'product' => $g->first()['schedule']->product?->only(['id', 'name_ar', 'sku']),
            'needed' => round($g->sum(fn ($r) => $r['schedule']->default_quantity), 3),
            'on_hand' => round((float) ($stock[$productId] ?? 0), 3),
            'items' => $g->count(),
        ])->map(fn ($p) => $p + ['short' => max(0, round($p['needed'] - $p['on_hand'], 3))])->sortByDesc('short')->values();

        return Inertia::render('Equipment/Maintenance/Dashboard', [
            'due' => $rows,
            'counts' => [
                'overdue' => $due->where('status.state', 'overdue')->count(),
                'due_soon' => $due->where('status.state', 'due_soon')->count(),
                'in_workshop' => MaintenanceOrder::where('status', 'open')->count(),
                'out_of_service' => Equipment::where('status', 'out_of_service')->count(),
                'parts_short' => $parts->where('short', '>', 0)->count(),
            ],
            'open' => MaintenanceOrder::with('equipment:id,code,name')->withCount('items')->where('status', 'open')->oldest('date')->get(),
            'parts' => $parts,
            'month' => [
                'completed' => MaintenanceOrder::where('status', 'completed')->where('completed_at', '>=', now()->startOfMonth())->count(),
                'parts_cost' => round((float) MaintenanceOrderItem::where('created_at', '>=', now()->startOfMonth())->sum('amount'), 2),
            ],
        ]);
    }

    public function store(Request $request, MaintenanceService $service)
    {
        $data = $request->validate([
            'equipment_id' => 'required|tenant_exists:equipment,id',
            'type' => 'required|in:preventive,corrective',
            'date' => 'required|date',
            'hours_reading' => 'nullable|numeric|min:0',
            'km_reading' => 'nullable|numeric|min:0',
            'technician' => 'nullable|string|max:100',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'schedule_ids' => 'array',
            'schedule_ids.*' => 'integer',
            'notes' => 'nullable|string|max:2000',
        ], [], ['equipment_id' => 'المعدة', 'date' => 'التاريخ']);
        $equipment = Equipment::findOrFail($data['equipment_id']);
        if (! array_key_exists('schedule_ids', $data) && $data['type'] === 'preventive') {
            $data['schedule_ids'] = $equipment->schedules()->whereIn('alert_state', ['due_soon', 'overdue'])->pluck('id')->all();
        }
        foreach (['hours_reading', 'km_reading'] as $k) {
            if (($data[$k] ?? '') === '') {
                unset($data[$k]);
            }
        }
        $order = $service->open($equipment, $data);

        return redirect()->route('equipment.maintenance.show', $order)->with('success', "تم فتح أمر الصيانة {$order->number}");
    }

    public function show(MaintenanceOrder $order)
    {
        $order->load(['equipment', 'warehouse:id,name_ar', 'items.product:id,name_ar,sku', 'items.warehouse:id,name_ar', 'items.issuer:id,name', 'schedules:id']);
        $equipment = $order->equipment;
        $schedules = $equipment->schedules()->with('product:id,name_ar,sku')->where('is_active', true)->orderBy('name')->get()
            ->map(fn ($s) => $s->only(['id', 'name', 'product_id', 'default_quantity', 'interval_hours', 'interval_km', 'interval_days']) + [
                'product' => $s->product?->only(['id', 'name_ar', 'sku']),
                'status' => $this->tracker->status($s, $equipment),
            ]);
        $productIds = $schedules->pluck('product_id')->filter()->merge($order->items->pluck('product_id'))->unique();

        return Inertia::render('Equipment/Maintenance/Show', [
            'order' => $order,
            'selected' => $order->schedules->pluck('id'),
            'schedules' => $schedules,
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
            'stock' => InventoryStock::whereIn('product_id', $productIds)->get(['product_id', 'warehouse_id', 'quantity'])
                ->groupBy('product_id')->map(fn ($g) => $g->pluck('quantity', 'warehouse_id')),
        ]);
    }

    /** Stock on hand per warehouse for one product (issue form). */
    public function stock(Request $request)
    {
        $request->validate(['product_id' => 'required|tenant_exists:products,id']);
        $product = Product::findOrFail($request->integer('product_id'));

        return response()->json([
            'product' => $product->only(['id', 'name_ar', 'sku']),
            'stock' => InventoryStock::where('product_id', $product->id)->pluck('quantity', 'warehouse_id'),
        ]);
    }

    public function issue(Request $request, MaintenanceOrder $order, MaintenanceService $service)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            'date' => 'nullable|date',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|tenant_exists:products,id',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.schedule_id' => 'nullable|integer',
        ], [], ['warehouse_id' => 'المستودع', 'lines.*.quantity' => 'الكمية', 'lines.*.product_id' => 'الصنف']);
        $total = $service->issue($order, $data['lines'], (int) $data['warehouse_id'], $data['date'] ?? null);

        return back()->with('success', 'تم صرف الأصناف وخصمها من المخزون بتكلفة '.number_format($total, 2));
    }

    public function complete(Request $request, MaintenanceOrder $order, MaintenanceService $service)
    {
        $data = $request->validate([
            'schedule_ids' => 'array',
            'schedule_ids.*' => 'integer',
            'hours_reading' => 'nullable|numeric|min:0',
            'km_reading' => 'nullable|numeric|min:0',
            'date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);
        foreach (['hours_reading', 'km_reading'] as $k) {
            if (($data[$k] ?? '') === '') {
                unset($data[$k]);
            }
        }
        $service->complete($order, $data['schedule_ids'] ?? [], $data);

        return back()->with('success', 'تم إكمال الصيانة وإعادة احتساب مواعيد البنود المنفذة');
    }

    public function destroy(MaintenanceOrder $order)
    {
        if ($order->status !== 'open' || $order->items()->exists()) {
            return back()->with('error', 'لا يمكن حذف أمر صيانة مكتمل أو صُرفت عليه أصناف.');
        }
        $equipment = $order->equipment;
        $order->delete();
        $equipment->syncStatus();

        return redirect()->route('equipment.maintenance.index')->with('success', 'تم حذف أمر الصيانة');
    }
}

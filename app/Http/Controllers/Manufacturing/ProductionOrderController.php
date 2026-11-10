<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionReceipt;
use App\Models\QcInspection;
use App\Models\Routing;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Models\WorkCenter;
use App\Services\Manufacturing\ManufacturingReports;
use App\Services\Manufacturing\ProductionService;
use App\Services\TenantManager;
use App\Support\PickerOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductionOrderController extends Controller
{
    public function __construct(protected ProductionService $production) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => 'nullable|in:'.implode(',', ProductionOrder::STATUSES), 'search' => 'nullable|string|max:100']);
        $orders = ProductionOrder::with('product:id,name_ar,sku')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('order_number', 'like', "%{$v}%")->orWhereHas('product', fn ($p) => $p->where('name_ar', 'like', "%{$v}%"))))
            ->orderByDesc('id')->paginate($this->perPage($request, 15))->withQueryString();

        return Inertia::render('Manufacturing/Orders/Index', [
            'orders' => $orders, 'filters' => $filters,
            'counts' => ProductionOrder::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Manufacturing/Orders/Create', [
            'products' => PickerOptions::from(Product::where('is_active', true)->where('type', '!=', 'service')->orderBy('name_ar'), ['id', 'name_ar', 'sku'], [$request->input('product_id')]),
            'boms' => Bom::where('is_active', true)->get(['id', 'product_id', 'name', 'version', 'is_default', 'routing_id']),
            'routings' => Routing::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderByDesc('is_main')->get(['id', 'name_ar']),
            'salesOrders' => SalesOrder::whereIn('status', ['confirmed', 'partially_delivered'])->latest('id')->limit(100)->get(['id', 'order_number']),
            'preset' => $request->only(['product_id', 'quantity', 'sales_order_id']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|tenant_exists:products,id',
            'quantity' => 'required|numeric|min:0.0001',
            'bom_id' => 'nullable|tenant_exists:boms,id',
            'routing_id' => 'nullable|tenant_exists:routings,id',
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            'output_warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'planned_start' => 'nullable|date',
            'planned_end' => 'nullable|date|after_or_equal:planned_start',
            'sales_order_id' => 'nullable|tenant_exists:sales_orders,id',
            'priority' => 'nullable|integer|min:1|max:5',
            'notes' => 'nullable|string|max:2000',
        ]);
        $order = $this->production->create($data);

        return redirect()->route('manufacturing.orders.show', $order)->with('success', "تم إنشاء أمر الإنتاج {$order->order_number}");
    }

    public function show(ProductionOrder $order, ManufacturingReports $reports): Response
    {
        $order->load(['product:id,name_ar,sku', 'bom:id,name,version', 'warehouse:id,name_ar', 'outputWarehouse:id,name_ar', 'materials.product:id,name_ar,sku,average_cost',
            'operations.workCenter:id,code,name', 'timeLogs.workCenter:id,name', 'timeLogs.employee:id,name', 'receipts']);

        return Inertia::render('Manufacturing/Orders/Show', [
            'order' => $order,
            'sheet' => $reports->costSheet($order),
            'workCenters' => WorkCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'employees' => Employee::where('status', '!=', 'terminated')->orderBy('name')->limit(300)->get(['id', 'name']),
            'inspections' => QcInspection::where('source_type', (new ProductionReceipt)->getMorphClass())->whereIn('source_id', $order->receipts->pluck('id'))->get(['id', 'inspection_number', 'source_id', 'result']),
            'wip' => $order->wipBalance(),
        ]);
    }

    public function print(ProductionOrder $order): Response
    {
        $order->load(['product:id,name_ar,sku', 'warehouse:id,name_ar', 'materials.product:id,name_ar,sku', 'operations.workCenter:id,name']);

        return Inertia::render('Manufacturing/Orders/Print', ['order' => $order, 'company' => app(TenantManager::class)->getCompany()?->only(['name'])]);
    }

    public function release(ProductionOrder $order)
    {
        $this->production->release($order);

        return back()->with('success', 'أُفرج عن أمر الإنتاج.');
    }

    public function issue(Request $request, ProductionOrder $order)
    {
        $data = $request->validate(['material_id' => 'required|integer', 'quantity' => 'required|numeric|min:0.0001', 'date' => 'nullable|date']);
        $material = ProductionOrderMaterial::where('production_order_id', $order->id)->findOrFail($data['material_id']);
        $cost = $this->production->issueMaterial($order, $material, (float) $data['quantity'], $data['date'] ?? null);

        return back()->with('success', 'تم صرف المادة بتكلفة '.number_format($cost, 2));
    }

    public function issueAll(Request $request, ProductionOrder $order)
    {
        $cost = $this->production->issueRemaining($order, $request->input('date'));

        return back()->with('success', 'تم صرف المواد المتبقية بتكلفة '.number_format($cost, 2));
    }

    public function return(Request $request, ProductionOrder $order)
    {
        $data = $request->validate(['material_id' => 'required|integer', 'quantity' => 'required|numeric|min:0.0001', 'date' => 'nullable|date']);
        $material = ProductionOrderMaterial::where('production_order_id', $order->id)->findOrFail($data['material_id']);
        $this->production->returnMaterial($order, $material, (float) $data['quantity'], $data['date'] ?? null);

        return back()->with('success', 'تم إرجاع المادة إلى المستودع.');
    }

    public function time(Request $request, ProductionOrder $order)
    {
        $data = $request->validate([
            'work_center_id' => 'required|tenant_exists:work_centers,id', 'operation_id' => 'nullable|integer', 'employee_id' => 'nullable|tenant_exists:employees,id',
            'minutes' => 'required|numeric|min:0.01', 'quantity' => 'nullable|numeric|min:0', 'date' => 'nullable|date', 'notes' => 'nullable|string|max:500',
        ]);
        $this->production->logTime($order, $data);

        return back()->with('success', 'تم تسجيل وقت التشغيل.');
    }

    public function receive(Request $request, ProductionOrder $order)
    {
        $data = $request->validate([
            'quantity' => 'required|numeric|min:0', 'scrap_quantity' => 'nullable|numeric|min:0', 'scrap_abnormal' => 'boolean',
            'date' => 'nullable|date', 'batch_number' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:500',
        ]);
        $receipt = $this->production->receive($order, $data);

        return back()->with('success', $receipt->status === 'pending_qc' ? 'سُجّل الاستلام وينتظر فحص الجودة.' : 'تم استلام المنتج التام.');
    }

    public function close(Request $request, ProductionOrder $order)
    {
        $this->production->close($order, $request->input('date'));

        return back()->with('success', 'أُغلق أمر الإنتاج.');
    }

    public function cancel(ProductionOrder $order)
    {
        $this->production->cancel($order);

        return back()->with('success', 'أُلغي أمر الإنتاج.');
    }

    public function destroy(ProductionOrder $order)
    {
        abort_unless($order->status === 'draft', 422, 'يُحذف أمر المسودة فقط.');
        $order->materials()->delete();
        $order->operations()->delete();
        $order->delete();

        return redirect()->route('manufacturing.orders.index')->with('success', 'تم حذف أمر الإنتاج.');
    }
}

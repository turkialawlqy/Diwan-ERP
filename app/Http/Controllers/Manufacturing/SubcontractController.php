<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\Product;
use App\Models\SubcontractOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Manufacturing\SubcontractService;
use App\Support\PickerOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubcontractController extends Controller
{
    public function __construct(protected SubcontractService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Manufacturing/Subcontracts/Index', [
            'orders' => SubcontractOrder::with(['supplier:id,name', 'product:id,name_ar'])->orderByDesc('id')->paginate($this->perPage($request, 15))->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Manufacturing/Subcontracts/Create', [
            'suppliers' => PickerOptions::from(Supplier::where('status', 'active')->orderBy('name'), ['id', 'name']),
            'products' => PickerOptions::from(Product::where('is_active', true)->where('type', '!=', 'service')->orderBy('name_ar'), ['id', 'name_ar', 'sku']),
            'boms' => Bom::where('is_active', true)->get(['id', 'product_id', 'name', 'version', 'is_default']),
            'warehouses' => Warehouse::where('is_active', true)->orderByDesc('is_main')->get(['id', 'name_ar']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|tenant_exists:suppliers,id', 'product_id' => 'required|tenant_exists:products,id', 'bom_id' => 'nullable|tenant_exists:boms,id',
            'quantity' => 'required|numeric|min:0.0001', 'service_price' => 'required|numeric|min:0',
            'source_warehouse_id' => 'required|tenant_exists:warehouses,id', 'vendor_warehouse_id' => 'required|tenant_exists:warehouses,id', 'output_warehouse_id' => 'required|tenant_exists:warehouses,id',
            'order_date' => 'required|date', 'expected_date' => 'nullable|date|after_or_equal:order_date', 'notes' => 'nullable|string|max:2000',
        ]);
        $order = $this->service->create($data);

        return redirect()->route('manufacturing.subcontracts.show', $order)->with('success', "تم إنشاء أمر التصنيع لدى الغير {$order->order_number}");
    }

    public function show(SubcontractOrder $order): Response
    {
        return Inertia::render('Manufacturing/Subcontracts/Show', [
            'order' => $order->load(['supplier:id,name', 'product:id,name_ar', 'materials.product:id,name_ar,sku', 'receipts', 'sourceWarehouse', 'vendorWarehouse']),
        ]);
    }

    public function send(SubcontractOrder $order)
    {
        $this->service->send($order);

        return back()->with('success', 'أُرسلت المواد إلى المصنّع.');
    }

    public function receive(Request $request, SubcontractOrder $order)
    {
        $data = $request->validate(['quantity' => 'required|numeric|min:0.0001', 'date' => 'nullable|date', 'notes' => 'nullable|string|max:500']);
        $this->service->receive($order, (float) $data['quantity'], $data['date'] ?? null, $data['notes'] ?? null);

        return back()->with('success', 'تم استلام المنتج المصنّع.');
    }

    public function cancel(SubcontractOrder $order)
    {
        $this->service->cancel($order);

        return back()->with('success', 'أُلغي الأمر وأُعيدت المواد.');
    }
}

<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Warehouse;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\Tax\TaxProfile;
use App\Services\Workflow\ApprovalService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PurchaseRequestController extends Controller
{
    public function __construct(protected PurchaseCycleService $cycle, protected ApprovalService $approvals) {}

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|string|max:30']);

        $requests = PurchaseRequest::with('requester:id,name')->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('request_number', 'like', "%{$s}%"))
            ->orderByDesc('request_date')->orderByDesc('id')
            ->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Purchases/Requests/Index', ['requests' => $requests, 'filters' => $filters]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'request_date' => 'required|date',
            'required_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'nullable|tenant_exists:products,id',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.estimated_price' => 'nullable|numeric|min:0',
        ]);

        $purchaseRequest = $this->cycle->createRequest($validated, $validated['items']);

        return redirect()->route('purchases.requests.show', $purchaseRequest->id)->with('success', "تم إنشاء طلب الشراء {$purchaseRequest->request_number}");
    }

    public function show(PurchaseRequest $purchaseRequest)
    {
        $purchaseRequest->load(['items.product:id,name_ar,sku,purchase_price', 'requester:id,name']);

        return Inertia::render('Purchases/Requests/Show', [
            'request' => $purchaseRequest,
            'approvals' => $this->approvals->history($purchaseRequest),
            'orders' => PurchaseOrder::where('purchase_request_id', $purchaseRequest->id)->get(['id', 'order_number', 'status', 'total']),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
        ]);
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->approvals->approve($purchaseRequest, 'approved', $request->input('comment'));

        return back()->with('success', 'تم اعتماد طلب الشراء');
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->approvals->reject($purchaseRequest, $request->input('comment'));

        return back()->with('success', 'تم رفض طلب الشراء');
    }

    /**
     * Turn an approved request into a draft purchase order for a supplier.
     */
    public function convert(Request $request, PurchaseRequest $purchaseRequest)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|tenant_exists:suppliers,id',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
        ]);

        if ($purchaseRequest->status !== 'approved') {
            throw ValidationException::withMessages(['request' => 'طلب الشراء غير معتمد أو تم تحويله.']);
        }

        $purchaseRequest->load('items');
        $order = $this->cycle->createOrder([
            'supplier_id' => $validated['supplier_id'],
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'purchase_request_id' => $purchaseRequest->id,
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => $purchaseRequest->required_date?->toDateString(),
            'notes' => 'من طلب الشراء '.$purchaseRequest->request_number,
        ], $purchaseRequest->items->map(fn ($i) => [
            'product_id' => $i->product_id,
            'item_name' => $i->item_name,
            'quantity' => (float) $i->quantity,
            'unit_price' => (float) $i->estimated_price,
            'tax_rate' => TaxProfile::rate(),
        ])->all());

        return redirect()->route('purchases.orders.edit', $order->id)->with('success', "تم إنشاء مسودة أمر الشراء {$order->order_number}");
    }
}

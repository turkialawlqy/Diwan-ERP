<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\Purchases\PurchaseCycleService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GoodsReceiptController extends Controller
{
    public function __construct(protected PurchaseCycleService $cycle) {}

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:received,invoiced,cancelled']);

        $receipts = GoodsReceipt::with(['supplier:id,name', 'purchaseOrder:id,order_number', 'warehouse:id,name_ar'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('receipt_number', 'like', "%{$s}%")
                ->orWhere('supplier_delivery_number', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('receipt_date')->orderByDesc('id')
            ->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Purchases/Receipts/Index', ['receipts' => $receipts, 'filters' => $filters]);
    }

    public function show(GoodsReceipt $receipt)
    {
        $receipt->load(['supplier', 'warehouse:id,name_ar', 'purchaseOrder:id,order_number', 'items']);

        return Inertia::render('Purchases/Receipts/Show', ['receipt' => $receipt]);
    }

    /**
     * Receive goods against a purchase order.
     */
    public function store(Request $request, PurchaseOrder $order)
    {
        $validated = $request->validate([
            'receipt_date' => 'required|date',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'supplier_delivery_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.purchase_order_item_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.batch_number' => 'nullable|string|max:100',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.serials' => 'nullable|array',
            'lines.*.serials.*' => 'string|max:150',
        ]);

        $receipt = $this->cycle->receive($order, $validated, $validated['lines']);

        return redirect()->route('purchases.receipts.show', $receipt->id)->with('success', "تم تسجيل الاستلام {$receipt->receipt_number} وإضافة الكميات للمخزون");
    }
}

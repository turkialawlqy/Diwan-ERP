<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Rules\AllowedBranch;
use App\Services\Sales\SalesCycleService;
use App\Services\Workflow\ApprovalService;
use App\Support\BranchOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesOrderController extends Controller
{
    public function __construct(protected SalesCycleService $cycle, protected ApprovalService $approvals) {}

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|string|max:30']);

        $orders = SalesOrder::with('customer:id,name')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('order_number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('order_date')->orderByDesc('id')
            ->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Sales/Orders/Index', ['orders' => $orders, 'filters' => $filters]);
    }

    public function create()
    {
        return Inertia::render('Sales/Orders/Create', [
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
            'suggestedNumber' => $this->peekNumber('sales_order'),
            ...BranchOptions::forUser(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'quotation_id' => 'nullable|tenant_exists:quotations,id',
            'order_date' => 'required|date',
            'delivery_date' => 'nullable|date|after_or_equal:order_date',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'nullable|tenant_exists:products,id',
            'items.*.unit_id' => 'nullable|tenant_exists:units,id',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.description' => 'nullable|string|max:1000',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $order = $this->cycle->createOrder($validated, $validated['items']);

        return redirect()->route('sales.orders.show', $order->id)->with('success', $order->status === 'pending_approval'
            ? "تم إنشاء أمر البيع {$order->order_number} وإرساله للاعتماد"
            : "تم إنشاء أمر البيع {$order->order_number} واعتماده");
    }

    public function show(SalesOrder $order)
    {
        $order->load(['customer', 'warehouse:id,name_ar', 'salesperson:id,name', 'creator:id,name', 'items', 'deliveries:id,sales_order_id,delivery_number,delivery_date,status,invoice_id']);

        return Inertia::render('Sales/Orders/Show', [
            'order' => $order,
            'invoices' => Invoice::where('sales_order_id', $order->id)->get(['id', 'invoice_number', 'invoice_date', 'total', 'payment_status']),
            'approvals' => $this->approvals->history($order),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
        ]);
    }

    public function approve(Request $request, SalesOrder $order)
    {
        $this->approvals->approve($order, 'confirmed', $request->input('comment'));

        return back()->with('success', 'تم اعتماد أمر البيع');
    }

    public function reject(Request $request, SalesOrder $order)
    {
        $this->approvals->reject($order, $request->input('comment'));

        return back()->with('success', 'تم رفض أمر البيع');
    }

    public function cancel(SalesOrder $order)
    {
        if ($order->items()->where('delivered_quantity', '>', 0)->exists()) {
            return back()->with('error', 'لا يمكن إلغاء أمر بيع تم التسليم عليه.');
        }
        $order->update(['status' => 'cancelled']);

        return back()->with('success', 'تم إلغاء أمر البيع');
    }

    public function deliver(Request $request, SalesOrder $order)
    {
        $validated = $request->validate([
            'delivery_date' => 'required|date',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'receiver_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.sales_order_item_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.serials' => 'nullable|array',
            'lines.*.serials.*' => 'string|max:150',
            'lines.*.batch_id' => 'nullable|tenant_exists:product_batches,id',
        ]);

        $note = $this->cycle->deliver($order, $validated, $validated['lines']);

        return redirect()->route('sales.deliveries.show', $note->id)->with('success', "تم إصدار إذن التسليم {$note->delivery_number}");
    }

    public function invoice(SalesOrder $order)
    {
        $invoice = $this->cycle->invoiceOrder($order);

        return redirect()->route('sales.invoices.show', $invoice->id)->with('success', "تم إصدار الفاتورة {$invoice->invoice_number} من أمر البيع");
    }
}

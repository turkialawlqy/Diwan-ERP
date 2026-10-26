<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Rules\AllowedBranch;
use App\Services\Accounting\DocumentPoster;
use App\Services\Inventory\DocumentStock;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\TenantManager;
use App\Services\Workflow\ApprovalService;
use App\Support\BranchOptions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of purchase orders.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');

        $query = PurchaseOrder::with(['supplier', 'warehouse']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $orders = $query->orderBy('order_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $stats = [
            'total_orders' => PurchaseOrder::count(),
            'pending_orders' => PurchaseOrder::where('status', 'pending')->count(),
            'approved_orders' => PurchaseOrder::where('status', 'approved')->count(),
            'total_orders_value' => PurchaseOrder::sum('total'),
        ];

        return Inertia::render('Purchases/Orders/Index', [
            'orders' => $orders,
            'stats' => $stats,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Convert Purchase Order directly to a Purchase Invoice.
     */
    public function convertToInvoice(PurchaseOrder $order)
    {
        if ($order->status === 'converted_to_invoice') {
            return back()->with('error', 'تم تحويل أمر الشراء هذا إلى فاتورة شراء مسبقاً');
        }
        if (! in_array($order->status, ['pending', 'approved'], true)) {
            return back()->with('error', 'أمر الشراء غير معتمد، أو تم الاستلام عليه (استخدم فاتورة من الاستلامات).');
        }

        $companyId = app(TenantManager::class)->getTenantId()
            ?? auth()->user()?->company_id;

        if (! $companyId) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        $invoice = DB::transaction(function () use ($order, $companyId) {
            // Re-read under a row lock so concurrent conversions cannot double stock.
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'تم تحويل أمر الشراء هذا إلى فاتورة شراء مسبقاً',
                ]);
            }

            $invoiceNumber = $this->nextNumber('purchase_invoice');

            $purchaseInvoice = PurchaseInvoice::create([
                'company_id' => $companyId,
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->id,
                'warehouse_id' => $order->warehouse_id ?? Warehouse::first()?->id,
                'invoice_number' => $invoiceNumber,
                'supplier_invoice_number' => 'PO-REF-'.$order->order_number,
                'invoice_date' => Carbon::now()->toDateString(),
                'due_date' => Carbon::now()->addDays(30)->toDateString(),
                'payment_method' => 'credit',
                'payment_status' => 'unpaid',
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'tax_rate' => $order->tax_rate,
                'tax_amount' => $order->tax_amount,
                'total' => $order->total,
                'paid_amount' => 0,
                'remaining_amount' => $order->total,
                'notes' => 'محولة تلقائياً من أمر الشراء رقم: '.$order->order_number,
            ]);

            foreach ($order->items as $item) {
                $purchaseInvoice->items()->create([
                    'product_id' => $item->product_id,
                    'item_name' => $item->item_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount,
                    'tax_rate' => $item->tax_rate,
                    'tax_amount' => $item->tax_amount,
                    'total' => $item->total,
                ]);
            }

            app(DocumentStock::class)->receive(
                $order->items->filter(fn ($i) => $i->product_id)->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'quantity' => (float) $i->quantity,
                    'unit_cost' => ((float) $i->quantity * (float) $i->unit_price - (float) ($i->discount ?? 0)) / max((float) $i->quantity, 0.0001),
                ]),
                $purchaseInvoice->warehouse_id,
                'purchase',
                $purchaseInvoice,
                $purchaseInvoice->invoice_date,
                $purchaseInvoice->invoice_number,
            );

            // Update supplier balance
            if ($order->supplier) {
                $order->supplier->increment('current_balance', $order->total);
            }

            // Update order status (everything received and invoiced at once)
            $order->items()->update(['received_quantity' => DB::raw('quantity'), 'invoiced_quantity' => DB::raw('quantity')]);
            $order->update(['status' => 'converted_to_invoice']);

            app(DocumentPoster::class)->purchaseInvoice($purchaseInvoice);

            return $purchaseInvoice;
        });

        return redirect()->route('purchases.invoices.show', $invoice->id)
            ->with('success', 'تم تحويل أمر الشراء إلى فاتورة شراء بنجاح وتحديث أرصدة المخزون والمورد');
    }

    public function create()
    {
        return Inertia::render('Purchases/Orders/Form', [
            'order' => null,
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
            'suggestedNumber' => $this->peekNumber('purchase_order'),
            ...BranchOptions::forUser(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);
        $order = app(PurchaseCycleService::class)->createOrder($validated, $validated['items']);

        return redirect()->route('purchases.orders.show', $order->id)->with('success', "تم حفظ أمر الشراء {$order->order_number}");
    }

    public function show(PurchaseOrder $order)
    {
        $order->load(['supplier', 'warehouse:id,name_ar', 'items', 'creator:id,name', 'approver:id,name']);

        return Inertia::render('Purchases/Orders/Show', [
            'order' => $order,
            'receipts' => GoodsReceipt::where('purchase_order_id', $order->id)->get(['id', 'receipt_number', 'receipt_date', 'status', 'total_value']),
            'invoices' => PurchaseInvoice::where('purchase_order_id', $order->id)->get(['id', 'invoice_number', 'invoice_date', 'total']),
            'approvals' => app(ApprovalService::class)->history($order),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
        ]);
    }

    public function edit(PurchaseOrder $order)
    {
        if (! in_array($order->status, ['draft', 'rejected'], true)) {
            return redirect()->route('purchases.orders.show', $order->id)->with('error', 'لا يمكن تعديل أمر شراء بعد إرساله للاعتماد.');
        }

        return Inertia::render('Purchases/Orders/Form', [
            'order' => $order->load(['items', 'supplier:id,name']),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
            'suggestedNumber' => $order->order_number,
            ...BranchOptions::forUser(),
        ]);
    }

    public function update(Request $request, PurchaseOrder $order)
    {
        $validated = $this->validateOrder($request);
        app(PurchaseCycleService::class)->createOrder($validated, $validated['items'], $order);

        return redirect()->route('purchases.orders.show', $order->id)->with('success', 'تم تحديث أمر الشراء');
    }

    public function submit(PurchaseOrder $order)
    {
        $status = app(PurchaseCycleService::class)->submitOrder($order);

        return back()->with('success', $status === 'pending_approval' ? 'تم إرسال أمر الشراء للاعتماد' : 'تم اعتماد أمر الشراء');
    }

    public function approve(Request $request, PurchaseOrder $order)
    {
        app(ApprovalService::class)->approve($order, 'approved', $request->input('comment'));

        return back()->with('success', 'تم اعتماد أمر الشراء');
    }

    public function reject(Request $request, PurchaseOrder $order)
    {
        app(ApprovalService::class)->reject($order, $request->input('comment'));

        return back()->with('success', 'تم رفض أمر الشراء');
    }

    public function cancel(PurchaseOrder $order)
    {
        app(PurchaseCycleService::class)->cancelOrder($order);

        return back()->with('success', 'تم إلغاء أمر الشراء');
    }

    protected function validateOrder(Request $request): array
    {
        $validated = $request->validate([
            'supplier_id' => 'required|tenant_exists:suppliers,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:5000',
            'submit' => 'nullable|boolean',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'nullable|tenant_exists:products,id',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);
        $validated['submit'] = (bool) ($validated['submit'] ?? false);

        return $validated;
    }
}

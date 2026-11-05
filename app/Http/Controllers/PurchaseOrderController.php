<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Models\Warehouse;
use App\Rules\AllowedBranch;
use App\Services\Accounting\DocumentPoster;
use App\Services\Inventory\DocumentStock;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\RecycleBin;
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

        $orders = $query->inActiveYear('order_date')->orderBy('order_date', 'desc')
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

    /** Page: choose the quantity to invoice now from each line (the rest stays open for a later invoice). */
    public function invoiceForm(PurchaseOrder $order)
    {
        if (! in_array($order->status, ['pending', 'approved', 'partially_invoiced'], true)) {
            return redirect()->route('purchases.orders.show', $order->id)->with('error', 'لا يمكن فوترة هذا الأمر: غير معتمد أو تم الاستلام عليه (استخدم فاتورة من الاستلامات).');
        }
        $order->load(['supplier:id,name', 'items']);

        return Inertia::render('Purchases/Orders/Invoice', [
            'order' => $order,
            'items' => $order->items->map(fn ($i) => $i->toArray() + ['open_quantity' => round((float) $i->quantity - (float) $i->invoiced_quantity, 4)])->values(),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * Invoice a purchase order directly, all of it or in stages: the quantities
     * given in `lines` (default: everything still open). The order becomes
     * «partially invoiced» until every line is fully invoiced.
     */
    public function convertToInvoice(Request $request, PurchaseOrder $order)
    {
        $data = $request->validate([
            'lines' => 'nullable|array',
            'lines.*.item_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|min:0',
            'supplier_invoice_number' => 'nullable|string|max:100',
            'due_date' => 'nullable|date|after_or_equal:today',
            'payments' => 'nullable|array|max:10',
            'payments.*.method' => 'required|in:cash,card,bank_transfer',
            'payments.*.bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'payments.*.amount' => 'required|numeric|min:0.01',
        ]);
        if ($order->status === 'converted_to_invoice') {
            return back()->with('error', 'تم تحويل أمر الشراء هذا إلى فاتورة شراء مسبقاً');
        }
        if (! in_array($order->status, ['pending', 'approved', 'partially_invoiced'], true)) {
            return back()->with('error', 'أمر الشراء غير معتمد، أو تم الاستلام عليه (استخدم فاتورة من الاستلامات).');
        }

        $companyId = app(TenantManager::class)->getTenantId()
            ?? auth()->user()?->company_id;

        if (! $companyId) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        $invoice = DB::transaction(function () use ($order, $companyId, $data) {
            // Re-read under a row lock so concurrent conversions cannot double stock.
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['pending', 'approved', 'partially_invoiced'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'تم تحويل أمر الشراء هذا إلى فاتورة شراء مسبقاً',
                ]);
            }

            // Quantity to invoice per line: requested, capped by what is still open.
            $requested = isset($data['lines']) ? collect($data['lines'])->pluck('quantity', 'item_id') : null;
            $rows = [];
            foreach ($order->items as $item) {
                $open = round((float) $item->quantity - (float) $item->invoiced_quantity, 4);
                $qty = $requested === null ? $open : round((float) ($requested[$item->id] ?? 0), 4);
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > $open + 0.0001) {
                    throw ValidationException::withMessages(['lines' => "الكمية المطلوبة من «{$item->item_name}» أكبر من المتبقي للفوترة ({$open})."]);
                }
                $ratio = (float) $item->quantity > 0 ? $qty / (float) $item->quantity : 0;
                $discount = round((float) $item->discount * $ratio, 2);
                $net = round($qty * (float) $item->unit_price - $discount, 2);
                $tax = round($net * ((float) $item->tax_rate / 100), 2);
                $rows[] = compact('item', 'qty', 'discount', 'net', 'tax');
            }
            if (! $rows) {
                throw ValidationException::withMessages(['lines' => 'حدد كمية واحدة على الأقل للفوترة.']);
            }

            $subtotal = round(array_sum(array_column($rows, 'net')), 2);
            $taxTotal = round(array_sum(array_column($rows, 'tax')), 2);
            $discountTotal = round(array_sum(array_column($rows, 'discount')), 2);
            $total = round($subtotal + $taxTotal, 2);

            $payments = collect($data['payments'] ?? [])->values();
            $paid = round($payments->sum(fn ($p) => (float) $p['amount']), 2);
            if ($paid > $total + 0.005) {
                throw ValidationException::withMessages(['payments' => 'مجموع الدفعات أكبر من إجمالي الفاتورة.']);
            }
            $remaining = round($total - $paid, 2);

            $purchaseInvoice = PurchaseInvoice::create([
                'company_id' => $companyId,
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'warehouse_id' => $order->warehouse_id ?? Warehouse::first()?->id,
                'invoice_number' => $this->nextNumber('purchase_invoice'),
                'supplier_invoice_number' => ($data['supplier_invoice_number'] ?? null) ?: 'PO-REF-'.$order->order_number,
                'invoice_date' => Carbon::now()->toDateString(),
                'due_date' => ! empty($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : Carbon::now()->addDays(30)->toDateString(),
                'payment_method' => $payments->isNotEmpty() ? $payments->first()['method'] : 'credit',
                'payment_status' => $remaining <= 0.005 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid'),
                'subtotal' => $subtotal,
                'discount' => $discountTotal,
                'tax_rate' => $order->tax_rate,
                'tax_amount' => $taxTotal,
                'total' => $total,
                'paid_amount' => $paid,
                'remaining_amount' => $remaining,
                'bank_account_id' => $payments->first()['bank_account_id'] ?? null,
                'notes' => 'فوترة من أمر الشراء رقم: '.$order->order_number,
            ]);

            foreach ($rows as $r) {
                $purchaseInvoice->items()->create([
                    'product_id' => $r['item']->product_id,
                    'item_name' => $r['item']->item_name,
                    'quantity' => $r['qty'],
                    'unit_price' => $r['item']->unit_price,
                    'discount' => $r['discount'],
                    'tax_rate' => $r['item']->tax_rate,
                    'tax_amount' => $r['tax'],
                    'total' => round($r['net'] + $r['tax'], 2),
                    'purchase_order_item_id' => $r['item']->id,
                ]);
            }

            app(DocumentStock::class)->receive(
                collect($rows)->filter(fn ($r) => $r['item']->product_id)->map(fn ($r) => [
                    'product_id' => $r['item']->product_id,
                    'quantity' => (float) $r['qty'],
                    'unit_cost' => $r['net'] / max((float) $r['qty'], 0.0001),
                ]),
                $purchaseInvoice->warehouse_id,
                'purchase',
                $purchaseInvoice,
                $purchaseInvoice->invoice_date,
                $purchaseInvoice->invoice_number,
            );

            if ($remaining > 0 && $order->supplier) {
                $order->supplier->increment('current_balance', $remaining);
            }

            // One payment record per row (the invoice entry credits each row's own cash / bank account).
            foreach ($payments as $p) {
                SupplierPayment::create([
                    'company_id' => $companyId,
                    'supplier_id' => $order->supplier_id,
                    'purchase_invoice_id' => $purchaseInvoice->id,
                    'payment_number' => $this->nextNumber('supplier_payment'),
                    'amount' => $p['amount'],
                    'payment_date' => $purchaseInvoice->invoice_date,
                    'payment_method' => $p['method'],
                    'bank_account_id' => $p['bank_account_id'] ?? null,
                    'notes' => 'سداد مرتبط بفاتورة الشراء '.$purchaseInvoice->invoice_number,
                ]);
            }

            // Stock came in with the invoice: those quantities count as received and invoiced.
            foreach ($rows as $r) {
                $r['item']->increment('received_quantity', $r['qty']);
                $r['item']->increment('invoiced_quantity', $r['qty']);
            }
            app(PurchaseCycleService::class)->refreshOrderStatus($order);

            app(DocumentPoster::class)->purchaseInvoice($purchaseInvoice);

            return $purchaseInvoice;
        });

        return redirect()->route('purchases.invoices.show', $invoice->id)
            ->with('success', $order->fresh()->status === 'converted_to_invoice'
                ? 'تمت فوترة أمر الشراء بالكامل وتحديث أرصدة المخزون والمورد'
                : 'صدرت فاتورة بجزء من أمر الشراء، والباقي ما زال مفتوحًا للفوترة');
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

    public function destroy(PurchaseOrder $order, RecycleBin $bin)
    {
        $bin->trash($order);

        return redirect()->route('purchases.orders.index')->with('success', 'نُقل أمر الشراء إلى سلة المحذوفات (يُحفظ '.RecycleBin::RETENTION_DAYS.' يومًا)');
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

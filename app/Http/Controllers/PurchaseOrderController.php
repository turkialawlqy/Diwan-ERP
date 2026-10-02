<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\TenantManager;
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

        $companyId = app(TenantManager::class)->getTenantId()
            ?? auth()->user()?->company_id;

        if (! $companyId) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        $invoice = DB::transaction(function () use ($order, $companyId) {
            // Re-read under a row lock so concurrent conversions cannot double stock.
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'converted_to_invoice') {
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

                // Increment stock
                if ($item->product_id && $order->warehouse_id) {
                    $stock = InventoryStock::firstOrCreate([
                        'company_id' => $companyId,
                        'product_id' => $item->product_id,
                        'warehouse_id' => $order->warehouse_id,
                    ], ['quantity' => 0]);
                    $stock->increment('quantity', $item->quantity);
                }
            }

            // Update supplier balance
            if ($order->supplier) {
                $order->supplier->increment('current_balance', $order->total);
            }

            // Update order status
            $order->update(['status' => 'converted_to_invoice']);

            return $purchaseInvoice;
        });

        return redirect()->route('purchases.invoices.show', $invoice->id)
            ->with('success', 'تم تحويل أمر الشراء إلى فاتورة شراء بنجاح وتحديث أرصدة المخزون والمورد');
    }
}

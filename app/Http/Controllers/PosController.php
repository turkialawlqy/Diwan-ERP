<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Services\TenantManager;
use App\Services\ZatcaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PosController extends Controller
{
    /**
     * Main POS Entry Point: Either Open Session or POS Terminal.
     */
    public function index()
    {
        $user = auth()->user();

        // Check if there is an active/opened session for this user
        $session = PosSession::where('user_id', $user->id)
            ->where('status', 'opened')
            ->with(['register.warehouse', 'register.branch'])
            ->latest()
            ->first();

        if (! $session) {
            $registers = PosRegister::where('is_active', true)->get();

            return Inertia::render('POS/OpenSession', [
                'registers' => $registers,
            ]);
        }

        // Active session exists -> Load POS Terminal
        $products = Product::where('is_active', true)
            ->with(['category', 'unit', 'stocks'])
            ->get();

        $categories = Category::where('is_active', true)->get();

        $customers = Customer::where('status', 'active')
            ->get(['id', 'name', 'phone', 'tax_number', 'current_balance']);

        $company = app(TenantManager::class)->getCompany();

        return Inertia::render('POS/Terminal', [
            'session' => $session,
            'products' => $products,
            'categories' => $categories,
            'customers' => $customers,
            'company' => $company,
        ]);
    }

    /**
     * Open a new POS Session.
     */
    public function openSession(Request $request)
    {
        $validated = $request->validate([
            'pos_register_id' => 'required|tenant_exists:pos_registers,id',
            'opening_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $company = app(TenantManager::class)->getCompany();
        if (! $company) {
            return redirect()->route('dashboard')->with('error', 'لم يتم تحديد المنشأة. يرجى تسجيل الدخول مرة أخرى.');
        }
        $companyId = $company->id;

        $session = PosSession::create([
            'company_id' => $companyId,
            'user_id' => auth()->id(),
            'pos_register_id' => $validated['pos_register_id'],
            'opening_cash' => $validated['opening_cash'] ?? 0,
            'status' => 'opened',
            'opened_at' => Carbon::now(),
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('pos.index')->with('success', 'تم فتح جلسة نقاط البيع بنجاح');
    }

    /**
     * Process & finalize a POS order with ZATCA QR Code.
     */
    public function processOrder(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|tenant_exists:pos_sessions,id',
            'customer_id' => 'nullable|tenant_exists:customers,id',
            'payment_method' => 'required|string|in:cash,card,multiple',
            'paid_amount' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|tenant_exists:products,id',
            'items.*.item_name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
        ]);

        $session = PosSession::with('register')->findOrFail($validated['session_id']);
        $company = app(TenantManager::class)->getCompany();
        if (! $company) {
            return back()->with('error', 'لم يتم تحديد المنشأة.');
        }
        $warehouseId = $session->register?->warehouse_id;

        $order = DB::transaction(function () use ($validated, $session, $company, $warehouseId) {
            $subtotal = 0;
            $taxAmount = 0;
            $totalDiscount = (float) ($validated['discount'] ?? 0);

            $orderItems = [];
            foreach ($validated['items'] as $item) {
                $qty = (float) $item['quantity'];
                $price = (float) $item['unit_price'];
                $disc = (float) ($item['discount'] ?? 0);
                $taxRate = (float) ($item['tax_rate'] ?? 15.00);

                $lineSubtotal = ($qty * $price) - $disc;
                $lineTax = $lineSubtotal * ($taxRate / 100);
                $lineTotal = $lineSubtotal + $lineTax;

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;

                $orderItems[] = [
                    'product_id' => $item['product_id'],
                    'item_name' => $item['item_name'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount' => $disc,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'total' => $lineTotal,
                ];

                // Deduct stock if warehouse set
                if ($warehouseId) {
                    $stock = InventoryStock::where('product_id', $item['product_id'])
                        ->where('warehouse_id', $warehouseId)
                        ->lockForUpdate()
                        ->first();
                    if ($stock) {
                        if ($stock->quantity < $qty) {
                            throw ValidationException::withMessages([
                                'items' => "الكمية المتوفرة من \"{$item['item_name']}\" غير كافية (المتوفر: {$stock->quantity})",
                            ]);
                        }
                        $stock->decrement('quantity', $qty);
                    }
                }
            }

            $total = $subtotal + $taxAmount - $totalDiscount;
            $paidAmount = (float) $validated['paid_amount'];
            $changeAmount = max(0, $paidAmount - $total);

            // Generate ZATCA QR Code
            $timestamp = Carbon::now()->format('Y-m-d\TH:i:s\Z');
            $sellerName = $company?->name ?? 'الأستاذ';
            $vatNumber = $company?->tax_number ?? '300000000000003';
            $zatcaQr = ZatcaService::generateQrCode(
                $sellerName,
                $vatNumber,
                $timestamp,
                $total,
                $taxAmount
            );

            // Auto-generate order number using MAX to avoid race conditions
            $lastOrder = PosOrder::where('order_number', 'like', 'POS-'.date('y').'-%')
                ->orderByRaw('CAST(SUBSTRING_INDEX(order_number, "-", -1) AS UNSIGNED) DESC')
                ->value('order_number');
            $nextSeq = $lastOrder
                ? ((int) substr($lastOrder, strrpos($lastOrder, '-') + 1)) + 1
                : 1;
            $orderNumber = sprintf('POS-%s-%06d', date('y'), $nextSeq);

            // 1. Create linked Simplified Tax Invoice
            $invoice = Invoice::create([
                'company_id' => $company->id,
                'branch_id' => $session->register?->branch_id ?? Branch::first()?->id,
                'customer_id' => $validated['customer_id'] ?? Customer::first()?->id,
                'user_id' => auth()->id(),
                'invoice_number' => sprintf('INV-POS-%s-%06d', date('y'), $nextSeq),
                'invoice_date' => Carbon::now()->toDateString(),
                'supply_date' => Carbon::now()->toDateString(),
                'subtotal' => $subtotal,
                'discount_type' => 'fixed',
                'discount_value' => $totalDiscount,
                'discount_amount' => $totalDiscount,
                'tax_rate' => 15.00,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $total,
                'remaining_amount' => 0,
                'payment_status' => 'paid',
                'payment_method' => $validated['payment_method'],
                'notes' => 'فاتورة ضريبية مبسطة - نقطة بيع',
                'zatca_uuid' => (string) Str::uuid(),
                'zatca_qr' => $zatcaQr,
                'zatca_status' => 'reported',
            ]);

            // 2. Create PosOrder
            $posOrder = PosOrder::create([
                'company_id' => $company->id,
                'pos_session_id' => $session->id,
                'customer_id' => $validated['customer_id'] ?? null,
                'user_id' => auth()->id(),
                'invoice_id' => $invoice->id,
                'order_number' => $orderNumber,
                'subtotal' => $subtotal,
                'discount' => $totalDiscount,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'change_amount' => $changeAmount,
                'payment_method' => $validated['payment_method'],
                'status' => 'completed',
                'zatca_qr' => $zatcaQr,
            ]);

            foreach ($orderItems as $oItem) {
                $posOrder->items()->create($oItem);
                $invoice->items()->create($oItem);
            }

            return $posOrder;
        });

        $order->load(['items', 'customer']);

        return back()->with([
            'success' => 'تم إصدار الفاتورة وحفظ الطلب بنجاح',
            'latest_order' => $order,
        ]);
    }

    /**
     * Close the POS session and reconcile cash.
     */
    public function closeSession(Request $request, PosSession $session)
    {
        $validated = $request->validate([
            'closing_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $cashOrdersTotal = (float) $session->orders()
            ->where('payment_method', 'cash')
            ->where('status', 'completed')
            ->sum('total');

        $expectedCash = $session->opening_cash + $cashOrdersTotal;
        $closingCash = (float) $validated['closing_cash'];
        $difference = $closingCash - $expectedCash;

        $session->update([
            'closing_cash' => $closingCash,
            'expected_cash' => $expectedCash,
            'difference' => $difference,
            'status' => 'closed',
            'closed_at' => Carbon::now(),
            'notes' => $validated['notes'] ?? $session->notes,
        ]);

        return redirect()->route('pos.index')->with('success', 'تم إغلاق جلسة نقطة البيع وتسوية الصندوق بنجاح');
    }

    /**
     * Display POS Order History.
     */
    public function history()
    {
        $orders = PosOrder::with(['customer', 'user', 'session.register'])
            ->latest()
            ->paginate(20);

        return Inertia::render('POS/History', [
            'orders' => $orders,
        ]);
    }
}

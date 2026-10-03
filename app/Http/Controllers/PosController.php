<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Services\Accounting\DocumentPoster;
use App\Services\Inventory\DocumentStock;
use App\Services\TenantManager;
use App\Services\Zatca\EInvoiceManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PosController extends Controller
{
    protected const PRODUCT_PAGE = 60;

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

        // Active session exists -> Load POS Terminal with a bounded first page of
        // products; the terminal searches the rest through productSearch().
        $products = $this->productQuery($session->register?->warehouse_id)
            ->limit(self::PRODUCT_PAGE)
            ->get()
            ->each->append('total_stock');

        $categories = Category::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        $customers = Customer::where('status', 'active')
            ->latest('id')
            ->limit(200)
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
     * Product search for the terminal (name / SKU / barcode, optional category).
     * An exact barcode or SKU match is returned first so scanners work.
     */
    public function productSearch(Request $request)
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer',
            'exact' => 'nullable|boolean',
        ]);

        $session = PosSession::where('user_id', auth()->id())->where('status', 'opened')->with('register')->latest()->first();
        $q = trim((string) ($validated['q'] ?? ''));

        $query = $this->productQuery($session?->register?->warehouse_id)
            ->when($validated['category_id'] ?? null, fn ($query, $categoryId) => $query->where('category_id', $categoryId));

        if ($q !== '') {
            if ($request->boolean('exact')) {
                $query->where(fn ($w) => $w->where('barcode', $q)->orWhere('sku', $q));
            } else {
                $query->where(fn ($w) => $w->where('name_ar', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "{$q}%")
                    ->orWhere('barcode', 'like', "{$q}%"));
            }
        }

        return response()->json(['data' => $query->limit(self::PRODUCT_PAGE)->get()->each->append('total_stock')]);
    }

    /**
     * Active products with stock in the session warehouse (or all warehouses).
     */
    protected function productQuery(?int $warehouseId)
    {
        return Product::query()
            ->where('is_active', true)
            ->select(['id', 'category_id', 'unit_id', 'name_ar', 'name_en', 'sku', 'barcode', 'selling_price', 'tax_rate', 'track_inventory', 'image'])
            ->withSum(['stocks' => fn ($q) => $warehouseId ? $q->where('warehouse_id', $warehouseId) : $q], 'quantity')
            ->orderBy('name_ar');
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

        // Only the cashier who opened the session may sell on it, and only while it is open.
        if ((int) $session->user_id !== (int) auth()->id() || $session->status !== 'opened') {
            throw ValidationException::withMessages(['session_id' => 'جلسة نقطة البيع غير صالحة أو مغلقة.']);
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
            }

            // The order discount reduces the taxable amount (VAT is charged after discount).
            if ($totalDiscount > $subtotal) {
                throw ValidationException::withMessages(['discount' => 'الخصم لا يمكن أن يتجاوز قيمة الطلب.']);
            }
            if ($subtotal > 0 && $totalDiscount > 0) {
                $taxAmount = round($taxAmount * (($subtotal - $totalDiscount) / $subtotal), 2);
            }
            $subtotal = round($subtotal - $totalDiscount, 2);
            $taxAmount = round($taxAmount, 2);
            $total = round($subtotal + $taxAmount, 2);

            $paidAmount = (float) $validated['paid_amount'];
            $changeAmount = max(0, round($paidAmount - $total, 2));
            $collected = min($paidAmount, $total);
            $remaining = round($total - $collected, 2);

            // A short payment is a credit sale: only allowed for a named customer
            // when the company permits POS credit.
            if ($remaining > 0 && (empty($validated['customer_id']) || ! $company->setting('pos_allow_credit', false))) {
                throw ValidationException::withMessages(['paid_amount' => 'المبلغ المدفوع أقل من إجمالي الفاتورة.']);
            }

            $orderNumber = $this->nextNumber('pos_order');

            // 1. Create linked Simplified Tax Invoice
            $invoice = Invoice::create([
                'company_id' => $company->id,
                'branch_id' => $session->register?->branch_id ?? Branch::first()?->id,
                'customer_id' => $validated['customer_id'] ?? Customer::walkIn()->id,
                'user_id' => auth()->id(),
                'invoice_number' => $this->nextNumber('pos_invoice'),
                'invoice_date' => Carbon::now()->toDateString(),
                'supply_date' => Carbon::now()->toDateString(),
                'subtotal' => $subtotal,
                'discount_type' => 'fixed',
                'discount_value' => $totalDiscount,
                'discount_amount' => $totalDiscount,
                'tax_rate' => 15.00,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $collected,
                'remaining_amount' => $remaining,
                'payment_status' => $remaining > 0 ? ($collected > 0 ? 'partially_paid' : 'unpaid') : 'paid',
                'payment_method' => $validated['payment_method'] === 'cash' ? 'cash' : 'card',
                'bank_account_id' => $validated['payment_method'] === 'cash' ? $session->register?->bank_account_id : null,
                'notes' => 'فاتورة ضريبية مبسطة - نقطة بيع',
                'invoice_type' => 'simplified',
                'zatca_uuid' => (string) Str::uuid(),
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
            ]);

            foreach ($orderItems as $oItem) {
                $posOrder->items()->create($oItem);
                $invoice->items()->create($oItem);
            }

            if ($remaining > 0) {
                Customer::whereKey($validated['customer_id'])->increment('current_balance', $remaining);
            }

            // Stock leaves the register's warehouse at actual cost.
            $documentStock = app(DocumentStock::class);
            $warehouseId = $documentStock->warehouse($warehouseId);
            $invoice->forceFill(['warehouse_id' => $warehouseId])->saveQuietly();
            $cost = $documentStock->issue($orderItems, $warehouseId, 'sale', $invoice, $invoice->invoice_date, $orderNumber);

            app(DocumentPoster::class)->salesInvoice($invoice, $warehouseId, $cost);

            // Simplified tax invoice: QR now, reported to ZATCA within 24h (phase 2).
            app(EInvoiceManager::class)->stamp($invoice);
            $posOrder->forceFill(['zatca_qr' => $invoice->zatca_qr])->saveQuietly();

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

        // Only the session owner (or a POS manager) can close an open session.
        if ($session->status !== 'opened'
            || ((int) $session->user_id !== (int) auth()->id() && ! $request->user()->can('pos.sessions.manage'))) {
            abort(403, 'لا يمكنك إغلاق هذه الجلسة.');
        }

        // Cash actually kept in the drawer: cash received minus change given.
        $cashOrdersTotal = (float) $session->orders()
            ->where('payment_method', 'cash')
            ->where('status', 'completed')
            ->sum(DB::raw('paid_amount - change_amount'));

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

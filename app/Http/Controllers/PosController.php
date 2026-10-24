<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\DocumentStock;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Services\Zatca\EInvoiceManager;
use App\Services\Zatca\VatClassifier;
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
                'boxes' => BankAccount::where('is_active', true)->where('type', 'cash')->get(['id', 'name']),
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
            'boxes' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
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
            'float_bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'notes' => 'nullable|string',
        ]);

        $company = app(TenantManager::class)->getCompany();
        if (! $company) {
            return redirect()->route('dashboard')->with('error', 'لم يتم تحديد المنشأة. يرجى تسجيل الدخول مرة أخرى.');
        }
        $companyId = $company->id;

        DB::transaction(function () use ($validated, $companyId) {
            $session = PosSession::create([
                'company_id' => $companyId,
                'user_id' => auth()->id(),
                'pos_register_id' => $validated['pos_register_id'],
                'opening_cash' => $validated['opening_cash'] ?? 0,
                'status' => 'opened',
                'opened_at' => Carbon::now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // The opening float handed to the cashier moves from the treasury into the drawer.
            if (! empty($validated['float_bank_account_id']) && (float) $session->opening_cash > 0) {
                $this->moveCash(BankAccount::findOrFail($validated['float_bank_account_id']), $this->drawer($session, 'float_bank_account_id'),
                    (float) $session->opening_cash, "عهدة افتتاح وردية #{$session->id}");
            }
        });

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
            'cash_amount' => 'nullable|required_if:payment_method,multiple|numeric|min:0',
            'card_amount' => 'nullable|required_if:payment_method,multiple|numeric|min:0',
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

            // Lines: net after line discount, the order discount spread over the
            // lines by value, VAT per line on its net (rounded per line), so the
            // invoice lines add up to the header and a return credits real value.
            $classifier = app(VatClassifier::class);
            $profile = TaxProfile::forBranch($session->register?->branch_id ? (int) $session->register->branch_id : null);
            $lines = [];
            foreach ($validated['items'] as $item) {
                $qty = (float) $item['quantity'];
                $price = (float) $item['unit_price'];
                $disc = round((float) ($item['discount'] ?? 0), 2);
                if ($disc > round($qty * $price, 2)) {
                    throw ValidationException::withMessages(['items' => "خصم «{$item['item_name']}» أكبر من قيمته."]);
                }
                $requested = (float) ($item['tax_rate'] ?? $profile->standardRate());
                [$category, $code] = $classifier->classify(Product::find($item['product_id']), $requested, null, null, $profile);
                $lines[] = ['item' => $item, 'qty' => $qty, 'price' => $price, 'disc' => $disc, 'net' => round($qty * $price - $disc, 2),
                    'rate' => $category === 'S' ? $profile->rateFor($requested) : 0.0, 'category' => $category, 'code' => $code];
            }

            $gross = round(array_sum(array_column($lines, 'net')), 2);
            if ($totalDiscount > $gross) {
                throw ValidationException::withMessages(['discount' => 'الخصم لا يمكن أن يتجاوز قيمة الطلب.']);
            }
            $left = $totalDiscount;
            foreach ($lines as $i => &$line) {
                $share = $i === array_key_last($lines) ? $left : ($gross > 0 ? round($totalDiscount * $line['net'] / $gross, 2) : 0);
                $share = min($share, $line['net']);
                $left = round($left - $share, 2);
                $line['disc'] = round($line['disc'] + $share, 2);
                $line['net'] = round($line['net'] - $share, 2);
                $line['tax'] = round($line['net'] * $line['rate'] / 100, 2);
            }
            unset($line);

            $orderItems = [];
            $invoiceItems = [];
            foreach ($lines as $line) {
                $base = ['product_id' => $line['item']['product_id'], 'item_name' => $line['item']['item_name'], 'quantity' => $line['qty'], 'unit_price' => $line['price'],
                    'discount' => $line['disc'], 'tax_rate' => $line['rate'], 'tax_amount' => $line['tax'], 'total' => round($line['net'] + $line['tax'], 2)];
                $orderItems[] = $base;
                $invoiceItems[] = $base + ['subtotal' => $line['net'], 'vat_category' => $line['category'], 'vat_exemption_code' => $line['code']];
            }
            $subtotal = round(array_sum(array_column($lines, 'net')), 2);
            $taxAmount = round(array_sum(array_column($lines, 'tax')), 2);
            $total = round($subtotal + $taxAmount, 2);

            // Tender: change is only given from cash, a card never pays more than the total.
            [$cashTender, $cardTender] = match ($validated['payment_method']) {
                'multiple' => [(float) ($validated['cash_amount'] ?? 0), (float) ($validated['card_amount'] ?? 0)],
                'card' => [0.0, min((float) $validated['paid_amount'], $total)],
                default => [(float) $validated['paid_amount'], 0.0],
            };
            if ($cardTender > $total + 0.001) {
                throw ValidationException::withMessages(['card_amount' => 'مبلغ البطاقة أكبر من إجمالي الفاتورة.']);
            }
            $paidAmount = round($cashTender + $cardTender, 2);
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
                'discount_amount' => round(array_sum(array_column($lines, 'disc')), 2),
                'tax_rate' => $profile->standardRate(),
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $collected,
                'remaining_amount' => $remaining,
                'payment_status' => $remaining > 0 ? ($collected > 0 ? 'partially_paid' : 'unpaid') : 'paid',
                'payment_method' => $validated['payment_method'],
                'bank_account_id' => $validated['payment_method'] === 'card' ? null : $session->register?->bank_account_id,
                'notes' => $profile->invoiceTitle(true)[0].' - نقطة بيع',
                'invoice_type' => $profile->simplifiedInvoices() ? 'simplified' : 'standard',
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
                'cash_amount' => round($cashTender, 2),
                'card_amount' => round($cardTender, 2),
                'payment_method' => $validated['payment_method'],
                'status' => 'completed',
            ]);

            foreach ($orderItems as $i => $oItem) {
                $posOrder->items()->create($oItem);
                $invoice->items()->create($invoiceItems[$i]);
            }

            if ($remaining > 0) {
                Customer::whereKey($validated['customer_id'])->increment('current_balance', $remaining);
            }

            // Stock leaves the register's warehouse at actual cost.
            $documentStock = app(DocumentStock::class);
            $warehouseId = $documentStock->warehouse($warehouseId);
            $invoice->forceFill(['warehouse_id' => $warehouseId])->saveQuietly();
            $cost = $documentStock->issue($orderItems, $warehouseId, 'sale', $invoice, $invoice->invoice_date, $orderNumber);

            // Split tender: the cash part goes to the drawer, the card part to the bank.
            $poster = app(DocumentPoster::class);
            $cardPart = min($cardTender, $collected);
            $poster->salesInvoice($invoice, $warehouseId, $cost, 'sales_revenue', $validated['payment_method'] === 'multiple' ? [
                [$poster->cashAccount($session->register?->bank_account_id, 'cash'), round($collected - $cardPart, 2)],
                [$poster->cashAccount(null, 'card'), round($cardPart, 2)],
            ] : null);

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
            'deposit_bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'notes' => 'nullable|string',
        ]);

        // Only the session owner (or a POS manager) can close an open session.
        if ($session->status !== 'opened'
            || ((int) $session->user_id !== (int) auth()->id() && ! $request->user()->can('pos.sessions.manage'))) {
            abort(403, 'لا يمكنك إغلاق هذه الجلسة.');
        }

        // Cash actually kept in the drawer: cash received (the cash part of split tenders) minus change given.
        $cashOrdersTotal = $session->orders()->where('status', 'completed')->whereIn('payment_method', ['cash', 'multiple'])
            ->get(['payment_method', 'paid_amount', 'change_amount', 'cash_amount'])
            ->sum(fn ($o) => ($o->payment_method === 'cash' ? (float) $o->paid_amount : (float) $o->cash_amount) - (float) $o->change_amount);

        $expectedCash = round((float) $session->opening_cash + $cashOrdersTotal, 2);
        $closingCash = (float) $validated['closing_cash'];
        $difference = round($closingCash - $expectedCash, 2);

        DB::transaction(function () use ($session, $validated, $closingCash, $expectedCash, $difference) {
            $session->update([
                'closing_cash' => $closingCash,
                'expected_cash' => $expectedCash,
                'difference' => $difference,
                'status' => 'closed',
                'closed_at' => Carbon::now(),
                'notes' => $validated['notes'] ?? $session->notes,
            ]);

            // Shortage is an expense, overage a gain, against the drawer's box.
            if ($difference != 0.0) {
                $drawer = app(DocumentPoster::class)->cashAccount($session->register?->bank_account_id, 'cash');
                $this->posting()->post([
                    'date' => now(),
                    'reference' => 'POS-'.$session->id,
                    'description' => ($difference < 0 ? 'عجز' : 'زيادة')." صندوق وردية #{$session->id}",
                    'source' => $session,
                    'lines' => $difference < 0
                        ? [['role' => 'cash_over_short', 'debit' => -$difference], ['account_id' => $drawer, 'credit' => -$difference]]
                        : [['account_id' => $drawer, 'debit' => $difference], ['role' => 'cash_over_short', 'credit' => $difference]],
                ]);
            }

            // Counted cash handed back to the treasury.
            if (! empty($validated['deposit_bank_account_id']) && $closingCash > 0) {
                $this->moveCash($this->drawer($session, 'deposit_bank_account_id'), BankAccount::findOrFail($validated['deposit_bank_account_id']),
                    $closingCash, "توريد نقدية وردية #{$session->id}");
            }
        });

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

    protected function posting(): PostingService
    {
        return app(PostingService::class);
    }

    /** The register's own cash box (needed to move a float or a deposit). */
    protected function drawer(PosSession $session, string $field): BankAccount
    {
        $box = $session->register?->bank_account_id ? BankAccount::find($session->register->bank_account_id) : null;
        if (! $box) {
            throw ValidationException::withMessages([$field => 'اربط نقطة البيع بصندوق نقدية خاص بها أولاً (من إعدادات نقاط البيع).']);
        }

        return $box;
    }

    protected function moveCash(BankAccount $from, BankAccount $to, float $amount, string $description): void
    {
        if ((int) $from->id === (int) $to->id) {
            return;
        }
        app(DocumentPoster::class)->transfer($from, $to, round($amount, 2), now()->toDateString(), $this->nextNumber('transfer', now()->toDateString()), $description);
        $from->decrement('current_balance', $amount);
        $to->increment('current_balance', $amount);
    }
}

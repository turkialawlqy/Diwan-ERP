<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Category;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Rules\AllowedBranch;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\ExchangeRates;
use App\Services\Inventory\DocumentStock;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Support\BranchOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PurchaseInvoiceController extends Controller
{
    /**
     * Display a listing of purchase invoices.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = PurchaseInvoice::with(['supplier', 'warehouse', 'branch']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('supplier_invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($status) {
            $query->where('payment_status', $status);
        }

        if ($dateFrom) {
            $query->whereDate('invoice_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('invoice_date', '<=', $dateTo);
        }

        $invoices = $query->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString();

        // One aggregate query instead of loading every invoice into memory.
        $totals = PurchaseInvoice::query()
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total), 0) as total, COALESCE(SUM(paid_amount), 0) as paid, COALESCE(SUM(remaining_amount), 0) as due')
            ->first();
        $stats = [
            'total_invoices_count' => (int) $totals->cnt,
            'total_purchases_amount' => (float) $totals->total,
            'total_paid_amount' => (float) $totals->paid,
            'total_due_amount' => (float) $totals->due,
        ];

        return Inertia::render('Purchases/Invoices/Index', [
            'invoices' => $invoices,
            'stats' => $stats,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Show the form for creating a new purchase invoice.
     */
    public function create()
    {
        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        $suppliers = Supplier::where('status', 'active')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'company_name', 'tax_number', 'phone', 'current_balance']);

        $branches = Branch::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name_ar', 'name_en', 'branch_id']);
        if ($warehouses->isEmpty() && $company) {
            Warehouse::firstOrCreate(
                ['company_id' => $company->id, 'code' => 'WH-01'],
                [
                    'name_ar' => 'المستودع الرئيسي',
                    'name_en' => 'Main Warehouse',
                    'branch_id' => $branches->first()?->id,
                    'is_main' => true,
                    'is_active' => true,
                ]
            );
            $warehouses = Warehouse::where('is_active', true)->get(['id', 'name_ar', 'name_en', 'branch_id']);
        }

        // First slice only; the form searches the rest via /lookup/*.
        $products = Product::where('is_active', true)
            ->orderBy('name_ar')
            ->limit(200)
            ->get(['id', 'name_ar', 'name_en', 'sku', 'barcode', 'purchase_price', 'selling_price', 'tax_rate']);

        $categories = Category::where('is_active', true)->get(['id', 'name_ar', 'name_en']);
        $units = Unit::all(['id', 'name_ar', 'name_en']);

        $suggestedNumber = $this->peekNumber('purchase_invoice');

        return Inertia::render('Purchases/Invoices/Create', [
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
            ...BranchOptions::forUser(),
            'products' => $products,
            'categories' => $categories,
            'units' => $units,
            'suggestedNumber' => $suggestedNumber,
            'defaultTaxRate' => TaxProfile::rate(),
        ]);
    }

    /**
     * Store a newly created purchase invoice and increment warehouse stock.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|tenant_exists:suppliers,id',
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'invoice_number' => 'nullable|string|max:50', // ignored: assigned by the system
            'supplier_invoice_number' => 'nullable|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'supply_date' => 'nullable|date',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,credit',
            'payment_status' => 'required|string|in:paid,partially_paid,unpaid',
            'paid_amount' => 'nullable|numeric|min:0',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|tenant_exists:products,id',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.batch_number' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.serials' => 'nullable|array',
            'items.*.serials.*' => 'string|max:150',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
        ] + ExchangeRates::rules());
        [$validated['currency_code'], $validated['exchange_rate']] = app(ExchangeRates::class)
            ->resolve($validated['currency_code'] ?? null, $validated['exchange_rate'] ?? null, $validated['invoice_date']);
        $rate = $validated['exchange_rate'];

        $companyId = app(TenantManager::class)->getTenantId()
            ?? auth()->user()?->company_id;

        if (! $companyId) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        app(PurchaseCycleService::class)->assertUniqueSupplierInvoice((int) $validated['supplier_id'], $validated['supplier_invoice_number'] ?? null);

        $invoice = DB::transaction(function () use ($validated, $companyId, $rate) {
            $validated['invoice_number'] = $this->nextNumber('purchase_invoice', $validated['invoice_date']);

            $subtotal = 0;
            $taxAmount = 0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $qty = (float) $item['quantity'];
                $price = (float) $item['unit_price'];
                $disc = (float) ($item['discount'] ?? 0);
                $taxRate = (float) ($item['tax_rate'] ?? TaxProfile::rate());

                $lineSubtotal = ($qty * $price) - $disc;
                $lineTax = $lineSubtotal * ($taxRate / 100);
                $lineTotal = $lineSubtotal + $lineTax;

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;

                $itemsData[] = [
                    'product_id' => $item['product_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount' => $disc,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'total' => $lineTotal,
                ];
            }

            $total = $subtotal + $taxAmount;
            $paidAmount = 0;
            if ($validated['payment_status'] === 'paid') {
                $paidAmount = $total;
            } elseif ($validated['payment_status'] === 'partially_paid') {
                $paidAmount = min($total, (float) ($validated['paid_amount'] ?? 0));
            }
            $remainingAmount = max(0, $total - $paidAmount);

            // Create Purchase Invoice
            $purchaseInvoice = PurchaseInvoice::create([
                'company_id' => $companyId,
                'supplier_id' => $validated['supplier_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'branch_id' => $validated['branch_id'] ?? null,
                'invoice_number' => $validated['invoice_number'],
                'supplier_invoice_number' => $validated['supplier_invoice_number'] ?? null,
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'supply_date' => $validated['supply_date'] ?? null,
                'payment_method' => $validated['payment_method'],
                'payment_status' => $validated['payment_status'],
                'subtotal' => $subtotal,
                'discount' => 0,
                'tax_rate' => TaxProfile::rate(),
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remainingAmount,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'currency_code' => $validated['currency_code'],
                'exchange_rate' => $rate,
            ]);

            foreach ($itemsData as $iData) {
                $purchaseInvoice->items()->create($iData);
            }

            // Goods received into the warehouse at net unit cost (excluding VAT,
            // after line discount); updates moving average / FIFO layers.
            $receiptLines = collect($validated['items'])->filter(fn ($i) => ! empty($i['product_id']))->map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity' => (float) $i['quantity'],
                'unit_id' => $i['unit_id'] ?? null,
                // Stock is valued in base currency at the bill's rate.
                'unit_cost' => ((float) $i['quantity'] * (float) $i['unit_price'] - (float) ($i['discount'] ?? 0)) / max((float) $i['quantity'], 0.0001) * $rate,
                'batch_number' => $i['batch_number'] ?? null,
                'expiry_date' => $i['expiry_date'] ?? null,
                'serials' => $i['serials'] ?? [],
            ]);
            app(DocumentStock::class)->receive($receiptLines, (int) $validated['warehouse_id'], 'purchase', $purchaseInvoice, $validated['invoice_date'], $validated['invoice_number']);

            // ⭐ Update Supplier Balance
            $supplier = Supplier::find($validated['supplier_id']);
            if ($supplier) {
                // If unpaid balance remains, supplier balance increases (we owe them)
                $supplier->increment('current_balance', ExchangeRates::toBase($remainingAmount, $rate));
            }

            // ⭐ Record Payment if paid or partially paid
            if ($paidAmount > 0) {
                SupplierPayment::create([
                    'company_id' => $companyId,
                    'supplier_id' => $validated['supplier_id'],
                    'purchase_invoice_id' => $purchaseInvoice->id,
                    'payment_number' => $this->nextNumber('supplier_payment', $validated['invoice_date']),
                    'amount' => $paidAmount,
                    'payment_date' => $validated['invoice_date'],
                    'payment_method' => $validated['payment_method'],
                    'reference_number' => $validated['supplier_invoice_number'] ?? null,
                    'bank_account_id' => $validated['bank_account_id'] ?? null,
                    'notes' => 'سداد مرتبط بفاتورة الشراء '.$validated['invoice_number'],
                    'currency_code' => $validated['currency_code'],
                    'exchange_rate' => $rate,
                ]);
            }

            // One entry covers the bill and its immediate payment (the payment
            // record above is informational and is not posted separately).
            app(DocumentPoster::class)->purchaseInvoice($purchaseInvoice);

            return $purchaseInvoice;
        });

        return redirect()->route('purchases.invoices.show', $invoice->id)
            ->with('success', 'تم إنشاء فاتورة الشراء وتحديث كميات المخزون بنجاح');
    }

    /**
     * Supplier bill for goods already received (three-way match against PO and GRN).
     */
    public function fromReceipts(Request $request)
    {
        $supplierId = $request->integer('supplier_id') ?: null;

        return Inertia::render('Purchases/Invoices/FromReceipts', [
            'supplier' => $supplierId ? Supplier::find($supplierId, ['id', 'name', 'tax_number']) : null,
            'openItems' => $supplierId ? GoodsReceiptItem::with('receipt:id,receipt_number,receipt_date,purchase_order_id')
                ->whereHas('receipt', fn ($q) => $q->where('supplier_id', $supplierId)->where('status', 'received'))
                ->whereColumn('invoiced_quantity', '<', 'quantity')
                ->limit(500)->get()
                ->map(function ($item) {
                    $poItem = $item->purchase_order_item_id ? PurchaseOrderItem::find($item->purchase_order_item_id) : null;

                    return $item->toArray() + [
                        'open_quantity' => (float) $item->quantity - (float) $item->invoiced_quantity,
                        'po_unit_price' => $poItem ? (float) $poItem->unit_price : null,
                        'po_discount_per_unit' => $poItem && (float) $poItem->quantity > 0 ? (float) $poItem->discount / (float) $poItem->quantity : 0,
                        'tax_rate' => $poItem ? (float) $poItem->tax_rate : TaxProfile::rate(),
                    ];
                }) : [],
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
            'suggestedNumber' => $this->peekNumber('purchase_invoice'),
        ]);
    }

    public function storeFromReceipts(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|tenant_exists:suppliers,id',
            'supplier_invoice_number' => 'required|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'payment_method' => 'required|in:cash,card,bank_transfer,credit',
            'payment_status' => 'required|in:paid,partially_paid,unpaid',
            'paid_amount' => 'nullable|numeric|min:0',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'accept_price_variance' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:500',
            'lines.*.goods_receipt_item_id' => 'required|tenant_exists:goods_receipt_items,id',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $invoice = app(PurchaseCycleService::class)->invoiceReceipts($validated, $validated['lines']);

        return redirect()->route('purchases.invoices.show', $invoice->id)->with('success', "تم تسجيل فاتورة المورد {$invoice->invoice_number} مقابل الاستلامات");
    }

    /**
     * Display the specified purchase invoice.
     */
    public function show(PurchaseInvoice $invoice)
    {
        $invoice->load(['supplier', 'warehouse', 'branch', 'items.product', 'payments', 'returns:id,purchase_invoice_id,return_number,return_date,total', 'landedCosts:id,purchase_invoice_id,landed_number,date,description,amount']);
        $company = app(TenantManager::class)->getCompany();

        return Inertia::render('Purchases/Invoices/Show', [
            'invoice' => $invoice,
            'company' => $company,
        ]);
    }

    /**
     * Remove the specified purchase invoice (Blocked by accounting principles).
     */
    public function destroy(PurchaseInvoice $invoice)
    {
        return redirect()->route('purchases.invoices.index')
            ->with('error', 'وفقاً للأصول والمعايير المحاسبية، يُمنع حذف فواتير الشراء المسجلة للحفاظ على التسلسل المالي وسلامة المخزون وأرصدة الموردين. يمكنك تسجيل مرتجع مشتريات بدلاً من الحذف.');
    }
}

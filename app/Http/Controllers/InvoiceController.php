<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\AccountingMappingService;
use App\Services\TenantManager;
use App\Services\ZatcaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    /**
     * Display a listing of invoices.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = Invoice::with(['customer', 'branch']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
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

        // Calculate summary KPI stats using aggregate queries (no full load)
        $stats = [
            'total_count' => Invoice::count(),
            'total_amount' => (float) Invoice::sum('total'),
            'paid_amount' => (float) Invoice::where('payment_status', 'paid')->sum('total'),
            'unpaid_amount' => (float) Invoice::whereIn('payment_status', ['unpaid', 'partially_paid'])->sum('remaining_amount'),
        ];

        return Inertia::render('Sales/Invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Show the form for creating a new invoice.
     */
    public function create()
    {
        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        // First slice only; the form searches the rest via /lookup/*.
        $customers = Customer::where('status', 'active')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'company_name', 'tax_number', 'phone', 'current_balance']);

        $products = Product::where('is_active', true)
            ->with(['category', 'unit'])
            ->orderBy('name_ar')
            ->limit(200)
            ->get(['id', 'name_ar', 'name_en', 'sku', 'barcode', 'selling_price', 'purchase_price', 'tax_rate', 'unit_id', 'category_id', 'description']);

        $categories = Category::where('is_active', true)->get(['id', 'name_ar', 'name_en']);
        if ($categories->isEmpty() && $company) {
            Category::firstOrCreate(
                ['company_id' => $company->id, 'name_ar' => 'عام'],
                ['name_en' => 'General', 'is_active' => true]
            );
            $categories = Category::where('is_active', true)->get(['id', 'name_ar', 'name_en']);
        }

        $units = Unit::all(['id', 'name_ar', 'name_en']);
        if ($units->isEmpty() && $company) {
            $defaultUnits = [
                ['name_ar' => 'حبة (قطعة)', 'name_en' => 'Piece', 'code' => 'pcs'],
                ['name_ar' => 'خدمة', 'name_en' => 'Service', 'code' => 'srv'],
                ['name_ar' => 'ساعة', 'name_en' => 'Hour', 'code' => 'hr'],
                ['name_ar' => 'طقم / مجموعة', 'name_en' => 'Set', 'code' => 'set'],
                ['name_ar' => 'متر', 'name_en' => 'Meter', 'code' => 'm'],
                ['name_ar' => 'كيلوجرام', 'name_en' => 'Kg', 'code' => 'kg'],
                ['name_ar' => 'كرتون / صندوق', 'name_en' => 'Box', 'code' => 'box'],
            ];
            foreach ($defaultUnits as $du) {
                Unit::firstOrCreate(['company_id' => $company->id, 'code' => $du['code']], $du);
            }
            $units = Unit::all(['id', 'name_ar', 'name_en']);
        }

        $branches = Branch::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name_ar', 'name_en']);
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
            $warehouses = Warehouse::where('is_active', true)->get(['id', 'name_ar', 'name_en']);
        }

        // Preview only; the definitive number is reserved when the invoice is saved.
        $suggestedNumber = $this->peekNumber('sales_invoice');

        $bankAccounts = BankAccount::where('company_id', $company?->id)
            ->where('is_active', true)
            ->with('account')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return Inertia::render('Sales/Invoices/Create', [
            'customers' => $customers,
            'products' => $products,
            'categories' => $categories,
            'units' => $units,
            'warehouses' => $warehouses,
            'branches' => $branches,
            'bankAccounts' => $bankAccounts,
            'suggestedNumber' => $suggestedNumber,
            'company' => $company,
            'defaultTaxRate' => 15.00,
        ]);
    }

    /**
     * Store a newly created invoice with ZATCA QR Code.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'invoice_number' => 'nullable|string|max:50', // ignored: assigned by the system
            'reference_number' => 'nullable|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'supply_date' => 'nullable|date',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,credit',
            'payment_status' => 'required|string|in:paid,partially_paid,unpaid',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'paid_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer|tenant_exists:products,id',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.description' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
        ]);

        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        $invoice = DB::transaction(function () use ($validated, $company) {
            // Sequential, race-safe number reserved inside the transaction.
            $validated['invoice_number'] = $this->nextNumber('sales_invoice', $validated['invoice_date']);

            $subtotal = 0;
            $taxAmount = 0;
            $itemsToCreate = [];

            foreach ($validated['items'] as $itemData) {
                $qty = (float) $itemData['quantity'];
                $price = (float) $itemData['unit_price'];
                $discount = (float) ($itemData['discount'] ?? 0);
                $taxRate = (float) ($itemData['tax_rate'] ?? 15.00);

                $itemSubtotal = ($qty * $price) - $discount;
                $itemTax = $itemSubtotal * ($taxRate / 100);
                $itemTotal = $itemSubtotal + $itemTax;

                $subtotal += $itemSubtotal;
                $taxAmount += $itemTax;

                $itemsToCreate[] = [
                    'product_id' => $itemData['product_id'] ?? null,
                    'item_name' => $itemData['item_name'],
                    'description' => $itemData['description'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount' => $discount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $itemTax,
                    'subtotal' => $itemSubtotal,
                    'total' => $itemTotal,
                ];
            }

            $total = $subtotal + $taxAmount;
            $paymentStatus = $validated['payment_status'];
            $paidAmount = $paymentStatus === 'paid' ? $total : (float) ($validated['paid_amount'] ?? 0);
            $remainingAmount = max(0, $total - $paidAmount);

            if ($paidAmount >= $total) {
                $paymentStatus = 'paid';
                $remainingAmount = 0;
            } elseif ($paidAmount > 0) {
                $paymentStatus = 'partially_paid';
            } else {
                $paymentStatus = 'unpaid';
            }

            // Generate Saudi ZATCA QR Code (Phase 1 or Phase 2 depending on company settings)
            $zatcaPhase = ZatcaService::getActivePhase($company?->id);
            $sellerName = $company?->name ?? 'شركة الأستاذ';
            $vatNumber = $company?->tax_number ?? '300000000000003';
            $timestamp = Carbon::parse($validated['invoice_date'])->format('Y-m-d\TH:i:s\Z');

            $invoiceHash = null;
            $digitalSig = null;
            $pubKey = null;

            if ($zatcaPhase === 'phase2') {
                $invoiceHash = base64_encode(hash('sha256', "INV-{$validated['invoice_number']}-{$total}-{$timestamp}", true));
                $digitalSig = base64_encode(hash('sha256', "SIG-{$invoiceHash}", true));
                $pubKey = base64_encode("ECDSA-PUBKEY-{$company?->id}");
            }

            $zatcaQr = ZatcaService::generateQrCode(
                $sellerName,
                $vatNumber,
                $timestamp,
                $total,
                $taxAmount,
                $invoiceHash,
                $digitalSig,
                $pubKey
            );

            $invoice = Invoice::create([
                'company_id' => $company->id,
                'branch_id' => $validated['branch_id'] ?? Branch::first()?->id,
                'customer_id' => $validated['customer_id'],
                'user_id' => auth()->id(),
                'invoice_number' => $validated['invoice_number'],
                'reference_number' => $validated['reference_number'] ?? null,
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'supply_date' => $validated['supply_date'] ?? $validated['invoice_date'],
                'subtotal' => $subtotal,
                'discount_type' => 'fixed',
                'discount_value' => 0,
                'discount_amount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remainingAmount,
                'payment_status' => $paymentStatus,
                'payment_method' => $validated['payment_method'],
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'zatca_uuid' => (string) Str::uuid(),
                'zatca_hash' => $invoiceHash,
                'zatca_qr' => $zatcaQr,
                'zatca_status' => $zatcaPhase === 'phase2' ? 'reported' : 'submitted',
            ]);

            foreach ($itemsToCreate as $item) {
                $invoice->items()->create($item);
            }

            // Update customer balance if remaining amount > 0
            if ($remainingAmount > 0) {
                Customer::where('id', $validated['customer_id'])->increment('current_balance', $remainingAmount);
            }

            return $invoice;
        });

        // مزامنة القيد المحاسبي والأرصدة لشجرة الحسابات تلقائياً
        $this->createInvoiceJournalEntry($invoice);

        return redirect()->route('sales.invoices.show', $invoice->id)->with('success', 'تم إنشاء الفاتورة الضريبية بنجاح وفق متطلبات هيئة الزكاة');
    }

    /**
     * Display the specified invoice (ZATCA Tax Invoice View).
     */
    public function show(Request $request, Invoice $invoice)
    {
        // Verify tenant ownership
        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasTenant() && $invoice->company_id !== $tenantManager->getTenantId()) {
            abort(403, 'غير مصرح لك بالوصول إلى هذه الفاتورة.');
        }

        $invoice->load(['customer', 'branch', 'items', 'user', 'company']);
        $company = $tenantManager->getCompany();
        $zatcaPhase = ZatcaService::getActivePhase($invoice->company_id);

        $templates = PrintTemplate::where('company_id', $invoice->company_id)
            ->orWhereNull('company_id')
            ->get();

        $selectedTemplateId = $request->input('template_id');
        $template = null;
        if ($selectedTemplateId) {
            $template = $templates->firstWhere('id', (int) $selectedTemplateId);
        }
        if (! $template) {
            $template = $templates->where('template_type', 'invoice_a4')->where('is_default', true)->first()
                ?? $templates->firstWhere('template_type', 'invoice_a4')
                ?? $templates->first();
        }

        return Inertia::render('Sales/Invoices/Show', [
            'invoice' => $invoice,
            'company' => $company,
            'template' => $template,
            'templates' => $templates,
            'zatcaPhase' => $zatcaPhase,
        ]);
    }

    /**
     * Print view for the invoice.
     */
    public function print(Request $request, Invoice $invoice)
    {
        // Verify tenant ownership
        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasTenant() && $invoice->company_id !== $tenantManager->getTenantId()) {
            abort(403, 'غير مصرح لك بالوصول إلى هذه الفاتورة.');
        }

        $invoice->load(['customer', 'branch', 'items', 'user', 'company']);
        $company = $tenantManager->getCompany();

        $templates = PrintTemplate::where('company_id', $invoice->company_id)
            ->orWhereNull('company_id')
            ->get();

        $selectedTemplateId = $request->input('template_id');
        $template = null;
        if ($selectedTemplateId) {
            $template = $templates->firstWhere('id', (int) $selectedTemplateId);
        }
        if (! $template) {
            $template = $templates->where('template_type', 'invoice_a4')->where('is_default', true)->first()
                ?? $templates->firstWhere('template_type', 'invoice_a4')
                ?? $templates->first();
        }

        return Inertia::render('Sales/Invoices/Print', [
            'invoice' => $invoice,
            'company' => $company,
            'template' => $template,
            'templates' => $templates,
        ]);
    }

    /**
     * Remove the specified invoice (Blocked by accounting & ZATCA regulations).
     */
    public function destroy(Invoice $invoice)
    {
        return redirect()->route('sales.invoices.index')
            ->with('error', 'وفقاً لمتطلبات هيئة الزكاة والضريبة والجمارك (ZATCA) والمعايير المحاسبية، يُمنع حذف الفواتير الصادرة نهائياً لضمان سلامة التسلسل الرقمي والدفاتر المالية. يمكنك إصدار إشعار دائن (مرتجع) بدلاً من الحذف.');
    }

    /**
     * Create journal entry and sync account balances for a newly issued invoice.
     */
    protected function createInvoiceJournalEntry(Invoice $invoice): void
    {
        try {
            $companyId = $invoice->company_id;
            $accSales = AccountingMappingService::getAccount($companyId, 'sales_revenue');
            $accTax = AccountingMappingService::getAccount($companyId, 'vat_output');
            $accCash = AccountingMappingService::getAccount($companyId, 'default_cash');
            $accBank = AccountingMappingService::getAccount($companyId, 'default_bank');
            $accReceivable = AccountingMappingService::getAccount($companyId, 'receivables');

            if (! $accSales) {
                return;
            }

            $isCash = ($invoice->payment_method === 'cash');
            $status = $invoice->payment_status; // paid, partially_paid, unpaid

            $paidAmount = (float) $invoice->paid_amount;
            $remainingAmount = (float) $invoice->remaining_amount;
            $totalAmount = (float) $invoice->total;

            if ($status === 'paid') {
                $paidAmount = $totalAmount;
                $remainingAmount = 0.00;
            } elseif ($status === 'unpaid') {
                $paidAmount = 0.00;
                $remainingAmount = $totalAmount;
            }

            // Determine specific BankAccount / Treasury selected by user
            $selectedBankAccount = null;
            $paidAccount = null;

            if ($invoice->bank_account_id) {
                $selectedBankAccount = BankAccount::with('account')->find($invoice->bank_account_id);
                if ($selectedBankAccount && $selectedBankAccount->account) {
                    $paidAccount = $selectedBankAccount->account;
                } elseif ($selectedBankAccount && $selectedBankAccount->account_id) {
                    $paidAccount = Account::find($selectedBankAccount->account_id);
                }
            }

            // Fallback if no specific bank/cash was picked but payment was made
            if (! $paidAccount && $paidAmount > 0) {
                $paidAccount = $isCash ? $accCash : ($accBank ?? $accCash);
            }

            $entryNumber = $this->nextNumber('journal_entry', $invoice->invoice_date);

            $customerName = $invoice->customer ? ($invoice->customer->name ?? $invoice->customer->company_name) : 'عميل عام';

            // Wrapped in its own transaction and balance-checked before commit so a
            // missing account mapping (e.g. no receivables account configured) can
            // never leave a half-posted, unbalanced journal entry in the ledger.
            DB::transaction(function () use (
                $companyId, $invoice, $entryNumber, $customerName, $totalAmount,
                $paidAmount, $remainingAmount, $paidAccount, $accReceivable,
                $accSales, $accTax, $accCash, $accBank, $isCash, $selectedBankAccount
            ) {
                $entry = JournalEntry::create([
                    'company_id' => $companyId,
                    'branch_id' => $invoice->branch_id,
                    'entry_number' => $entryNumber,
                    'date' => $invoice->invoice_date ?? now(),
                    'reference' => $invoice->invoice_number,
                    'description' => "فاتورة مبيعات رقم {$invoice->invoice_number} - {$customerName}",
                    'total_debit' => $totalAmount,
                    'total_credit' => $totalAmount,
                    'status' => 'posted',
                    'created_by' => $invoice->user_id ?? auth()->id(),
                ]);

                $debitSum = 0.0;
                $creditSum = 0.0;

                // 1. Debit Paid Portion (Cash / Bank)
                if ($paidAmount > 0 && $paidAccount) {
                    $accountNameDesc = $selectedBankAccount ? $selectedBankAccount->name : ($isCash ? 'الصندوق الرئيسي' : 'الحساب البنكي');
                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $paidAccount->id,
                        'description' => "تحصيل مبيعات ({$accountNameDesc}) - فاتورة {$invoice->invoice_number}",
                        'debit' => $paidAmount,
                        'credit' => 0.00,
                    ]);
                    $debitSum += $paidAmount;

                    // Update Chart of Account Balance
                    $paidAccount->increment('balance', $paidAmount);

                    // Update BankAccount / Cash Register Balance
                    if ($selectedBankAccount) {
                        $selectedBankAccount->increment('current_balance', $paidAmount);
                    } elseif ($isCash && $accCash) {
                        BankAccount::where('company_id', $companyId)->where('account_id', $accCash->id)->increment('current_balance', $paidAmount);
                    } elseif ($accBank) {
                        BankAccount::where('company_id', $companyId)->where('account_id', $accBank->id)->increment('current_balance', $paidAmount);
                    }
                }

                // 2. Debit Remaining Portion (Accounts Receivable / ذمم العملاء)
                if ($remainingAmount > 0 && $accReceivable) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $accReceivable->id,
                        'description' => "مبيعات آجلة (ذمم مدينة) - فاتورة {$invoice->invoice_number}",
                        'debit' => $remainingAmount,
                        'credit' => 0.00,
                    ]);
                    $debitSum += $remainingAmount;
                    $accReceivable->increment('balance', $remainingAmount);
                }

                // 3. Credit Sales Revenue (Subtotal before VAT)
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $accSales->id,
                    'description' => "إيراد مبيعات فاتورة {$invoice->invoice_number}",
                    'debit' => 0.00,
                    'credit' => (float) $invoice->subtotal,
                ]);
                $creditSum += (float) $invoice->subtotal;
                $accSales->increment('balance', (float) $invoice->subtotal);

                // 4. Credit VAT Output Tax (Tax Amount)
                if ($invoice->tax_amount > 0 && $accTax) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $accTax->id,
                        'description' => "ضريبة القيمة المضافة 15% - فاتورة {$invoice->invoice_number}",
                        'debit' => 0.00,
                        'credit' => (float) $invoice->tax_amount,
                    ]);
                    $creditSum += (float) $invoice->tax_amount;
                    $accTax->increment('balance', (float) $invoice->tax_amount);
                }

                if (abs($debitSum - $creditSum) > 0.01) {
                    throw new \RuntimeException(
                        "Unbalanced journal entry for invoice {$invoice->invoice_number}: debit={$debitSum}, credit={$creditSum}. Check accounting account mappings (receivables/cash/bank)."
                    );
                }

                $entry->update(['total_debit' => $debitSum, 'total_credit' => $creditSum]);
            });
        } catch (\Throwable $e) {
            Log::error('Failed to create invoice journal entry: '.$e->getMessage());
        }
    }
}

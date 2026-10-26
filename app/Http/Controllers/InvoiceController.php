<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Rules\AllowedBranch;
use App\Services\Accounting\ExchangeRates;
use App\Services\Documents\FreeformTemplates;
use App\Services\Sales\InvoiceService;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Services\ZatcaService;
use App\Support\BranchOptions;
use Illuminate\Http\Request;
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
            'total_amount' => Invoice::query()->sumBase('total'),
            'paid_amount' => Invoice::where('payment_status', 'paid')->sumBase('total'),
            'unpaid_amount' => Invoice::whereIn('payment_status', ['unpaid', 'partially_paid'])->sumBase('remaining_amount'),
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

        $bankAccounts = BankAccount::withLedgerBalances(BankAccount::where('company_id', $company?->id)
            ->where('is_active', true)
            ->with('account')
            ->orderBy('type')
            ->orderBy('name')
            ->get());

        return Inertia::render('Sales/Invoices/Create', [
            'customers' => $customers,
            'products' => $products,
            'categories' => $categories,
            'units' => $units,
            'warehouses' => $warehouses,
            ...BranchOptions::forUser(),
            'bankAccounts' => $bankAccounts,
            'suggestedNumber' => $suggestedNumber,
            'company' => $company,
            'defaultTaxRate' => TaxProfile::rate(),
        ]);
    }

    /**
     * Store a newly created invoice with ZATCA QR Code.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'invoice_number' => 'nullable|string|max:50', // ignored: assigned by the system
            'reference_number' => 'nullable|string|max:50',
            'invoice_date' => 'required|date|before_or_equal:today',
            'due_date' => 'nullable|date',
            'supply_date' => 'nullable|date',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,credit',
            'payment_status' => 'required|string|in:paid,partially_paid,unpaid',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'paid_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'salesperson_id' => 'nullable|tenant_exists:users,id',
            'invoice_type' => 'nullable|in:standard,simplified',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer|tenant_exists:products,id',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.batch_id' => 'nullable|integer',
            'items.*.serials' => 'nullable|array',
            'items.*.serials.*' => 'string|max:150',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.description' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
        ] + ExchangeRates::rules());

        $invoice = app(InvoiceService::class)->create(
            collect($validated)->except('items')->all(),
            $validated['items'],
        );

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
            $template = $templates->where('template_type', 'invoice')->where('is_default', true)->first(fn ($t) => FreeformTemplates::isFreeform($t))
                ?? $templates->where('template_type', 'invoice_a4')->where('is_default', true)->first()
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
}

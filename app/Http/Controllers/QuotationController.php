<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Rules\AllowedBranch;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\QuotationService;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Support\BranchOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class QuotationController extends Controller
{
    /**
     * Display a listing of quotations.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');

        $query = Quotation::with('customer');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $quotations = $query->orderBy('quotation_date', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $stats = [
            'total' => Quotation::count(),
            'accepted' => Quotation::where('status', 'accepted')->count(),
            'pending' => Quotation::whereIn('status', ['draft', 'sent'])->count(),
            'total_value' => (float) Quotation::sum('total'),
        ];

        return Inertia::render('Sales/Quotations/Index', [
            'quotations' => $quotations,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Show form for creating a new quotation.
     */
    public function create()
    {
        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        $customers = Customer::where('status', 'active')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'company_name', 'tax_number', 'phone']);

        $products = Product::where('is_active', true)
            ->orderBy('name_ar')
            ->limit(500)
            ->get(['id', 'name_ar', 'name_en', 'sku', 'barcode', 'selling_price', 'tax_rate']);

        $suggestedNumber = $this->peekNumber('quotation');

        return Inertia::render('Sales/Quotations/Create', [
            'customers' => $customers,
            ...BranchOptions::forUser(),
            'products' => $products,
            'suggestedNumber' => $suggestedNumber,
            'company' => $company,
            'defaultTaxRate' => TaxProfile::rate(),
        ]);
    }

    /**
     * Store a newly created quotation.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'quotation_number' => 'nullable|string|max:50', // ignored: assigned by the system
            'quotation_date' => 'required|date',
            'valid_until' => 'nullable|date',
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

        $quotation = app(QuotationService::class)->create($validated);

        return redirect()->route('sales.quotations.show', $quotation->id)
            ->with('success', 'تم إنشاء عرض السعر بنجاح');
    }

    /**
     * Show quotation details.
     */
    public function show(Quotation $quotation)
    {
        $quotation->load(['customer', 'items']);
        $company = app(TenantManager::class)->getCompany();

        return Inertia::render('Sales/Quotations/Show', [
            'quotation' => $quotation,
            'company' => $company,
        ]);
    }

    /**
     * Convert quotation into a tax invoice.
     */
    public function convertToInvoice(Quotation $quotation)
    {
        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        if ($quotation->status === 'converted') {
            return back()->with('error', 'تم تحويل عرض السعر هذا إلى فاتورة مسبقاً');
        }

        $invoice = DB::transaction(function () use ($quotation) {
            // Lock the quotation so two concurrent requests cannot both convert it.
            $quotation = Quotation::with('items')->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status === 'converted') {
                throw ValidationException::withMessages([
                    'quotation' => 'تم تحويل عرض السعر هذا إلى فاتورة مسبقاً',
                ]);
            }

            $invoice = app(InvoiceService::class)->create([
                'customer_id' => $quotation->customer_id,
                'branch_id' => $quotation->branch_id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'payment_method' => 'credit',
                'payment_status' => 'unpaid',
                'reference_number' => $quotation->quotation_number,
                'notes' => "تم التحويل من عرض سعر رقم {$quotation->quotation_number}",
            ], $quotation->items->map(fn ($q) => [
                'product_id' => $q->product_id,
                'item_name' => $q->item_name,
                'description' => $q->description,
                'quantity' => (float) $q->quantity,
                'unit_price' => (float) $q->unit_price,
                'discount' => (float) $q->discount,
                'tax_rate' => (float) $q->tax_rate,
            ])->all());

            $quotation->update(['status' => 'converted', 'converted_invoice_id' => $invoice->id]);

            return $invoice;
        });

        return redirect()->route('sales.invoices.show', $invoice->id)->with('success', 'تم تحويل عرض السعر إلى فاتورة مبيعات بنجاح');
    }
}

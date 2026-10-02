<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Services\Accounting\DocumentPoster;
use App\Services\TenantManager;
use App\Services\ZatcaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        $branches = Branch::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        $products = Product::where('is_active', true)
            ->orderBy('name_ar')
            ->limit(500)
            ->get(['id', 'name_ar', 'name_en', 'sku', 'barcode', 'selling_price', 'tax_rate']);

        $suggestedNumber = $this->peekNumber('quotation');

        return Inertia::render('Sales/Quotations/Create', [
            'customers' => $customers,
            'branches' => $branches,
            'products' => $products,
            'suggestedNumber' => $suggestedNumber,
            'company' => $company,
            'defaultTaxRate' => 15.00,
        ]);
    }

    /**
     * Store a newly created quotation.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'branch_id' => 'nullable|tenant_exists:branches,id',
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

        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        $quotation = DB::transaction(function () use ($validated, $company) {
            $subtotal = 0;
            $taxAmount = 0;
            $discountTotal = 0;
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
                $discountTotal += $discount;

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

            $quotation = Quotation::create([
                'company_id' => $company->id,
                'branch_id' => $validated['branch_id'] ?? null,
                'customer_id' => $validated['customer_id'],
                'user_id' => auth()->id(),
                'quotation_number' => $this->nextNumber('quotation', $validated['quotation_date']),
                'quotation_date' => $validated['quotation_date'],
                'valid_until' => $validated['valid_until'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'tax_rate' => 15.00,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
            ]);

            foreach ($itemsToCreate as $item) {
                $quotation->items()->create($item);
            }

            return $quotation;
        });

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

        $invoice = DB::transaction(function () use ($quotation, $company) {
            // Lock the quotation so two concurrent requests cannot both convert it.
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status === 'converted') {
                throw ValidationException::withMessages([
                    'quotation' => 'تم تحويل عرض السعر هذا إلى فاتورة مسبقاً',
                ]);
            }

            $invoiceNumber = $this->nextNumber('sales_invoice');

            $timestamp = Carbon::now()->format('Y-m-d\TH:i:s\Z');
            $zatcaQr = ZatcaService::generateQrCode(
                $company?->name ?? 'الأستاذ',
                $company?->tax_number ?? '300000000000003',
                $timestamp,
                $quotation->total,
                $quotation->tax_amount
            );

            $invoice = Invoice::create([
                'company_id' => $company->id,
                'customer_id' => $quotation->customer_id,
                'user_id' => auth()->id(),
                'invoice_number' => $invoiceNumber,
                'reference_number' => $quotation->quotation_number,
                'invoice_date' => Carbon::now()->toDateString(),
                'due_date' => Carbon::now()->addDays(30)->toDateString(),
                'subtotal' => $quotation->subtotal,
                'tax_rate' => 15.00,
                'tax_amount' => $quotation->tax_amount,
                'total' => $quotation->total,
                'paid_amount' => 0,
                'remaining_amount' => $quotation->total,
                'payment_status' => 'unpaid',
                'payment_method' => 'credit',
                'notes' => "تم التحويل من عرض سعر رقم {$quotation->quotation_number}",
                'zatca_uuid' => (string) Str::uuid(),
                'zatca_qr' => $zatcaQr,
                'zatca_status' => 'submitted',
            ]);

            foreach ($quotation->items as $qItem) {
                $invoice->items()->create([
                    'product_id' => $qItem->product_id,
                    'item_name' => $qItem->item_name,
                    'description' => $qItem->description,
                    'quantity' => $qItem->quantity,
                    'unit_price' => $qItem->unit_price,
                    'discount' => $qItem->discount,
                    'tax_rate' => $qItem->tax_rate,
                    'tax_amount' => $qItem->tax_amount,
                    'subtotal' => $qItem->subtotal,
                    'total' => $qItem->total,
                ]);
            }

            // Update customer balance
            Customer::where('id', $quotation->customer_id)->increment('current_balance', $quotation->total);

            // Update quotation status
            $quotation->update(['status' => 'converted']);

            app(DocumentPoster::class)->salesInvoice($invoice);

            return $invoice;
        });

        return redirect()->route('sales.invoices.show', $invoice->id)->with('success', 'تم تحويل عرض السعر إلى فاتورة مبيعات بنجاح');
    }
}

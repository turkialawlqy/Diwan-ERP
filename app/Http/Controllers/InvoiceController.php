<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\TenantManager;
use App\Services\ZatcaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->paginate($request->input('per_page', 20))
            ->withQueryString();

        // Calculate summary KPI stats
        $allInvoices = Invoice::all();
        $stats = [
            'total_count' => $allInvoices->count(),
            'total_amount' => (float) $allInvoices->sum('total'),
            'paid_amount' => (float) $allInvoices->where('payment_status', 'paid')->sum('total'),
            'unpaid_amount' => (float) $allInvoices->whereIn('payment_status', ['unpaid', 'partially_paid'])->sum('remaining_amount'),
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

        $customers = Customer::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'company_name', 'tax_number', 'phone', 'current_balance']);

        $branches = Branch::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        // Auto-generate invoice number format: INV-YY-BRANCH-XXXXXX
        $year = date('y');
        $branchId = $branches->first()?->id ?? 1;
        $count = Invoice::count() + 1;
        $suggestedNumber = sprintf("INV-%s-%s-%06d", $year, $branchId, $count);

        return Inertia::render('Sales/Invoices/Create', [
            'customers' => $customers,
            'branches' => $branches,
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
            'customer_id' => 'required|exists:customers,id',
            'branch_id' => 'nullable|exists:branches,id',
            'invoice_number' => 'required|string|max:50',
            'reference_number' => 'nullable|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'supply_date' => 'nullable|date',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,credit',
            'payment_status' => 'required|string|in:paid,partially_paid,unpaid',
            'paid_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.description' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
        ]);

        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        $invoice = DB::transaction(function () use ($validated, $company, $request) {
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

            // Generate Saudi ZATCA Phase 1 Base64 TLV QR Code
            $sellerName = $company?->name ?? 'شركة الأستاذ';
            $vatNumber = $company?->tax_number ?? '300000000000003';
            $timestamp = Carbon::parse($validated['invoice_date'])->format('Y-m-d\TH:i:s\Z');
            $zatcaQr = ZatcaService::generateQrCode(
                $sellerName,
                $vatNumber,
                $timestamp,
                $total,
                $taxAmount
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
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'zatca_uuid' => (string) Str::uuid(),
                'zatca_qr' => $zatcaQr,
                'zatca_status' => 'submitted',
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

        return redirect()->route('sales.invoices.show', $invoice->id)->with('success', 'تم إنشاء الفاتورة الضريبية ورمز ZATCA بنجاح');
    }

    /**
     * Display the specified invoice (ZATCA Tax Invoice View).
     */
    public function show(Invoice $invoice)
    {
        $invoice->load(['customer', 'branch', 'items', 'user']);
        
        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        return Inertia::render('Sales/Invoices/Show', [
            'invoice' => $invoice,
            'company' => $company,
        ]);
    }

    /**
     * Print view for the invoice.
     */
    public function print(Invoice $invoice)
    {
        $invoice->load(['customer', 'branch', 'items', 'user']);
        
        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        return Inertia::render('Sales/Invoices/Print', [
            'invoice' => $invoice,
            'company' => $company,
        ]);
    }

    /**
     * Remove the specified invoice.
     */
    public function destroy(Invoice $invoice)
    {
        DB::transaction(function () use ($invoice) {
            // Revert customer balance if remaining amount was recorded
            if ($invoice->remaining_amount > 0) {
                Customer::where('id', $invoice->customer_id)->decrement('current_balance', $invoice->remaining_amount);
            }
            $invoice->delete();
        });

        return redirect()->route('sales.invoices.index')->with('success', 'تم حذف الفاتورة بنجاح');
    }
}

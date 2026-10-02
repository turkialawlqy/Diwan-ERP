<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\TenantManager;
use App\Services\ZatcaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CreditNoteController extends Controller
{
    /**
     * Display a listing of sales returns / credit notes.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = CreditNote::with(['customer', 'invoice']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('credit_note_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $returns = $query->orderBy('credit_note_date', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $stats = [
            'total_returns' => CreditNote::count(),
            'total_amount' => (float) CreditNote::sum('total'),
        ];

        return Inertia::render('Sales/Returns/Index', [
            'returns' => $returns,
            'filters' => [
                'search' => $search,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Show form for creating a new sales return / credit note.
     */
    public function create(Request $request)
    {
        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        $selectedInvoice = null;
        if ($request->has('invoice_id')) {
            $selectedInvoice = Invoice::with(['customer', 'items'])
                ->find($request->input('invoice_id'));
        }

        $customers = Customer::where('status', 'active')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'company_name', 'tax_number', 'phone', 'current_balance']);

        // Recent invoices for selection if not pre-selected
        $recentInvoices = Invoice::with('customer')
            ->orderBy('invoice_date', 'desc')
            ->limit(50)
            ->get(['id', 'invoice_number', 'customer_id', 'total', 'invoice_date']);

        $suggestedNumber = $this->peekNumber('credit_note');

        return Inertia::render('Sales/Returns/Create', [
            'customers' => $customers,
            'recentInvoices' => $recentInvoices,
            'selectedInvoice' => $selectedInvoice,
            'suggestedNumber' => $suggestedNumber,
            'company' => $company,
            'defaultTaxRate' => 15.00,
        ]);
    }

    /**
     * Store a newly created credit note / sales return.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'invoice_id' => 'nullable|tenant_exists:invoices,id',
            'credit_note_number' => 'nullable|string|max:50', // ignored: assigned by the system
            'credit_note_date' => 'required|date',
            'subtotal' => 'required|numeric|min:0.01',
            'tax_amount' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
            'status' => 'required|in:draft,approved,refunded',
        ]);

        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        $creditNote = DB::transaction(function () use ($validated, $company) {
            $timestamp = Carbon::now()->format('Y-m-d\TH:i:s\Z');
            $zatcaQr = ZatcaService::generateQrCode(
                $company?->name ?? 'الأستاذ',
                $company?->tax_number ?? '300000000000003',
                $timestamp,
                $validated['total'],
                $validated['tax_amount']
            );

            $creditNote = CreditNote::create([
                'company_id' => $company->id,
                'customer_id' => $validated['customer_id'],
                'invoice_id' => $validated['invoice_id'] ?? null,
                'credit_note_number' => $this->nextNumber('credit_note', $validated['credit_note_date']),
                'credit_note_date' => $validated['credit_note_date'],
                'subtotal' => $validated['subtotal'],
                'tax_amount' => $validated['tax_amount'],
                'total' => $validated['total'],
                'reason' => $validated['reason'],
                'status' => $validated['status'],
                'zatca_qr' => $zatcaQr,
            ]);

            // If approved or refunded, deduct from customer current_balance
            if (in_array($validated['status'], ['approved', 'refunded'])) {
                Customer::where('id', $validated['customer_id'])
                    ->decrement('current_balance', $validated['total']);
            }

            return $creditNote;
        });

        return redirect()->route('sales.returns.show', $creditNote->id)
            ->with('success', 'تم إصدار الإشعار الدائن / المرتجع بنجاح');
    }

    /**
     * Display the specified credit note.
     */
    public function show(CreditNote $creditNote)
    {
        $creditNote->load(['customer', 'invoice.items']);
        $company = app(TenantManager::class)->getCompany();

        return Inertia::render('Sales/Returns/Show', [
            'creditNote' => $creditNote,
            'company' => $company,
        ]);
    }
}

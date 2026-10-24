<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\ExchangeRates;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesCycleService;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Services\Zatca\EInvoiceManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
            'total_amount' => CreditNote::query()->sumBase('total'),
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
            $selectedInvoice?->items->each(function ($item) {
                $item->returnable_quantity = max(0, (float) $item->quantity - (float) CreditNoteItem::where('invoice_item_id', $item->id)
                    ->whereHas('creditNote', fn ($q) => $q->whereIn('status', ['approved', 'refunded']))->sum('quantity'));
            });
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
            'defaultTaxRate' => TaxProfile::rate(),
        ]);
    }

    /**
     * Store a newly created credit note / sales return.
     */
    public function store(Request $request)
    {
        // Item level return: goods come back to stock, quantities are checked
        // against what is still returnable on the original invoice.
        if ($request->filled('items') && $request->filled('invoice_id')) {
            $validated = $request->validate([
                'customer_id' => 'required|tenant_exists:customers,id',
                'invoice_id' => 'required|tenant_exists:invoices,id',
                'credit_note_date' => 'required|date|before_or_equal:today',
                'reason' => 'required|string|max:500',
                'refund_method' => 'nullable|in:credit,cash,bank_transfer',
                'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
                'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
                'items' => 'required|array|min:1',
                'items.*.invoice_item_id' => 'required|integer',
                'items.*.quantity' => 'required|numeric|min:0',
                'items.*.return_to_stock' => 'nullable|boolean',
                'items.*.serials' => 'nullable|array',
                'items.*.serials.*' => 'string|max:150',
            ]);

            $invoice = Invoice::findOrFail($validated['invoice_id']);
            if ((int) $invoice->customer_id !== (int) $validated['customer_id']) {
                throw ValidationException::withMessages(['invoice_id' => 'الفاتورة المحددة لا تخص هذا العميل.']);
            }

            $creditNote = app(SalesCycleService::class)->returnInvoice($invoice, $validated, $validated['items']);
            app(InvoiceService::class)->stampCreditNote($creditNote);

            return redirect()->route('sales.returns.show', $creditNote->id)
                ->with('success', 'تم إصدار مرتجع المبيعات وإعادة الأصناف للمخزون');
        }

        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            // ZATCA: a credit note always references the invoice it corrects.
            'invoice_id' => 'required|tenant_exists:invoices,id',
            'credit_note_number' => 'nullable|string|max:50', // ignored: assigned by the system
            'credit_note_date' => 'required|date|before_or_equal:today',
            'subtotal' => 'required|numeric|min:0.01',
            'tax_amount' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
            'status' => 'required|in:draft,approved,refunded',
            'refund_method' => 'nullable|required_if:status,refunded|in:credit,cash,bank_transfer',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
        ], ['invoice_id.required' => 'اختر الفاتورة الأصلية التي يعدّلها الإشعار الدائن.']);

        // A return can never exceed what is left of the original invoice.
        if (! empty($validated['invoice_id'])) {
            $invoice = Invoice::findOrFail($validated['invoice_id']);
            if ((int) $invoice->customer_id !== (int) $validated['customer_id']) {
                throw ValidationException::withMessages(['invoice_id' => 'الفاتورة المحددة لا تخص هذا العميل.']);
            }
            if ($validated['status'] !== 'draft') {
                app(SalesCycleService::class)->guardCredit($invoice, (float) $validated['total'],
                    $validated['status'] === 'refunded' ? ($validated['refund_method'] ?? 'cash') : 'credit');
            }
        }

        if (abs(((float) $validated['subtotal'] + (float) $validated['tax_amount']) - (float) $validated['total']) > 0.01) {
            throw ValidationException::withMessages(['total' => 'الإجمالي يجب أن يساوي المبلغ قبل الضريبة + الضريبة.']);
        }
        // VAT is reversed at a rate of the invoice's country, or not at all for zero-rated / exempt supplies.
        $profile = ! empty($validated['invoice_id']) ? TaxProfile::forDocument(Invoice::findOrFail($validated['invoice_id'])) : TaxProfile::current();
        if ((float) $validated['tax_amount'] > 0 && ! $profile->vatMatches((float) $validated['subtotal'], (float) $validated['tax_amount'])) {
            throw ValidationException::withMessages(['tax_amount' => 'ضريبة الإشعار يجب أن تكون '.$profile->ratesLabel().' من المبلغ، أو صفراً للتوريدات الصفرية والمعفاة.']);
        }

        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        $creditNote = DB::transaction(function () use ($validated, $company) {
            // An amount credit note on an invoice follows the invoice currency.
            $source = ! empty($validated['invoice_id']) ? Invoice::find($validated['invoice_id']) : null;
            $creditNote = CreditNote::create([
                'currency_code' => $source?->currency_code,
                'exchange_rate' => $source?->exchange_rate ?: 1,
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
                'refund_method' => $validated['status'] === 'refunded' ? ($validated['refund_method'] ?? 'cash') : 'credit',
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'kind' => 'amount',
                'zatca_uuid' => (string) Str::uuid(),
            ]);

            // A credit to the customer's account lowers their balance; a cash/bank
            // refund settles it immediately.
            $creditsAccount = $creditNote->status === 'approved'
                || ($creditNote->status === 'refunded' && $creditNote->refund_method === 'credit');
            if ($creditsAccount) {
                // On an invoice the credit first closes what is still due on it.
                if ($source) {
                    app(SalesCycleService::class)->settleInvoice($source, (float) $validated['total'], 'credit');
                } else {
                    Customer::where('id', $validated['customer_id'])->decrement('current_balance', ExchangeRates::toBase((float) $validated['total'], $creditNote->exchange_rate));
                }
            }

            app(DocumentPoster::class)->creditNote($creditNote);
            if ($creditNote->status !== 'draft') {
                app(EInvoiceManager::class)->stamp($creditNote);
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
        $creditNote->load(['customer', 'invoice.items', 'items', 'warehouse:id,name_ar']);
        $company = app(TenantManager::class)->getCompany();

        return Inertia::render('Sales/Returns/Show', [
            'creditNote' => $creditNote,
            'company' => $company,
        ]);
    }
}

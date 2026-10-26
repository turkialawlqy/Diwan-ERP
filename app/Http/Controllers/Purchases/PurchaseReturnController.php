<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Services\Purchases\PurchaseCycleService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PurchaseReturnController extends Controller
{
    public function __construct(protected PurchaseCycleService $cycle) {}

    public function index(Request $request)
    {
        $search = $request->input('search');

        $returns = PurchaseReturn::with(['supplier:id,name', 'purchaseInvoice:id,invoice_number'])
            ->when($search, fn ($q, $s) => $q->where(fn ($q) => $q->where('return_number', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('return_date')->orderByDesc('id')
            ->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Purchases/Returns/Index', ['returns' => $returns, 'filters' => ['search' => $search]]);
    }

    public function create(Request $request)
    {
        $invoice = null;
        if ($request->filled('invoice_id')) {
            $invoice = PurchaseInvoice::with(['supplier:id,name', 'items'])->findOrFail($request->integer('invoice_id'));
            $invoice->items->each(fn ($i) => $i->returnable_quantity = max(0, (float) $i->quantity - (float) $i->returned_quantity));
        }

        return Inertia::render('Purchases/Returns/Create', [
            'invoice' => $invoice,
            'recentInvoices' => PurchaseInvoice::with('supplier:id,name')->orderByDesc('invoice_date')->limit(50)->get(['id', 'invoice_number', 'supplier_id', 'supplier_invoice_number', 'total', 'invoice_date']),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
            'suggestedNumber' => $this->peekNumber('purchase_return'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'purchase_invoice_id' => 'required|tenant_exists:purchase_invoices,id',
            'return_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
            'settlement' => 'required|in:credit,cash,bank_transfer',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.purchase_invoice_item_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.serials' => 'nullable|array',
            'items.*.serials.*' => 'string|max:150',
            'items.*.batch_id' => 'nullable|tenant_exists:product_batches,id',
        ]);

        $return = $this->cycle->returnInvoice(PurchaseInvoice::findOrFail($validated['purchase_invoice_id']), $validated, $validated['items']);

        return redirect()->route('purchases.returns.show', $return->id)->with('success', "تم تسجيل مرتجع المشتريات {$return->return_number}");
    }

    public function show(PurchaseReturn $return)
    {
        $return->load(['supplier', 'purchaseInvoice:id,invoice_number,supplier_invoice_number', 'warehouse:id,name_ar', 'items']);

        return Inertia::render('Purchases/Returns/Show', ['purchaseReturn' => $return]);
    }
}

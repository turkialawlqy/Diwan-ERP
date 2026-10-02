<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\LandedCost;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\PurchaseCycleService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LandedCostController extends Controller
{
    public function __construct(protected PurchaseCycleService $cycle) {}

    public function index(Request $request)
    {
        return Inertia::render('Purchases/LandedCosts/Index', [
            'costs' => LandedCost::with(['purchaseInvoice:id,invoice_number', 'supplier:id,name'])
                ->orderByDesc('date')->orderByDesc('id')
                ->paginate($this->perPage($request))->withQueryString(),
            'invoices' => PurchaseInvoice::orderByDesc('invoice_date')->limit(100)->get(['id', 'invoice_number', 'supplier_invoice_number', 'total']),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'purchase_invoice_id' => 'required|tenant_exists:purchase_invoices,id',
            'date' => 'required|date',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'paid_from' => 'required|in:payables,cash,bank_transfer',
            'supplier_id' => 'nullable|required_if:paid_from,payables|tenant_exists:suppliers,id',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
        ]);

        $landed = $this->cycle->landedCost(PurchaseInvoice::findOrFail($validated['purchase_invoice_id']), $validated);

        return back()->with('success', "تم تسجيل التكاليف الإضافية {$landed->landed_number} وتوزيعها على تكلفة الأصناف");
    }
}

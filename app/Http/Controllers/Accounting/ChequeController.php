<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Accounting\ChequeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ChequeController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'direction' => 'nullable|in:incoming,outgoing',
            'status' => 'nullable|in:pending,deposited,cleared,bounced,cancelled',
            'due_until' => 'nullable|date',
            'search' => 'nullable|string|max:100',
        ]);

        $cheques = Cheque::with('bankAccount:id,name')
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['due_until'] ?? null, fn ($q, $v) => $q->where('due_date', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('cheque_number', 'like', "%{$s}%")->orWhere('party_name', 'like', "%{$s}%")))
            ->orderBy('due_date')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $summary = Cheque::whereIn('status', ['pending', 'deposited'])
            ->selectRaw('direction, COUNT(*) as cnt, COALESCE(SUM(amount),0) as amount, COALESCE(SUM(CASE WHEN due_date <= ? THEN amount ELSE 0 END),0) as due_soon', [now()->addDays(7)->toDateString()])
            ->groupBy('direction')
            ->get()
            ->keyBy('direction');

        return Inertia::render('Accounting/Cheques/Index', [
            'cheques' => $cheques,
            'summary' => $summary,
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
            'transitions' => ChequeService::TRANSITIONS,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, ChequeService $cheques)
    {
        $validated = $request->validate([
            'direction' => 'required|in:incoming,outgoing',
            'cheque_number' => 'required|string|max:50',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'party_type' => 'required|in:customer,supplier',
            'party_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'invoice_id' => 'nullable|tenant_exists:invoices,id',
            'purchase_invoice_id' => 'nullable|tenant_exists:purchase_invoices,id',
            'notes' => 'nullable|string',
        ]);

        $party = $validated['party_type'] === 'customer'
            ? Customer::findOrFail($validated['party_id'])
            : Supplier::findOrFail($validated['party_id']);

        $cheques->register(array_merge($validated, ['party_name' => $party->name]));

        return back()->with('success', 'تم تسجيل الشيك وترحيل قيده');
    }

    public function transition(Request $request, Cheque $cheque, ChequeService $cheques)
    {
        $validated = $request->validate([
            'status' => 'required|in:deposited,cleared,bounced,cancelled',
            'date' => 'required|date',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
        ]);

        $cheques->transition($cheque, $validated['status'], Carbon::parse($validated['date']), $validated['bank_account_id'] ?? null);

        return back()->with('success', 'تم تحديث حالة الشيك');
    }
}

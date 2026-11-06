<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Services\Finance\BankAccountService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankAccountController extends Controller
{
    public function index(): Response
    {
        $accounts = BankAccount::withLedgerBalances(BankAccount::with('account')->latest()->get());

        $stats = [
            'total_bank_balance' => round($accounts->where('type', 'bank')->sum(fn ($b) => (float) $b->current_balance), 2),
            'total_cash_balance' => round($accounts->where('type', 'cash')->sum(fn ($b) => (float) $b->current_balance), 2),
            'total_liquidity' => round($accounts->sum(fn ($b) => (float) $b->current_balance), 2),
            'bank_count' => BankAccount::where('type', 'bank')->count(),
            'cash_count' => BankAccount::where('type', 'cash')->count(),
        ];

        $chartAccounts = Account::where('type', 'asset')->where('is_parent', false)->get(['id', 'code', 'name_ar']);

        return Inertia::render('Finance/Banks/Index', [
            'accounts' => $accounts,
            'stats' => $stats,
            'chartAccounts' => $chartAccounts,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Finance/Banks/Form', [
            'chartAccounts' => Account::where('type', 'asset')->where('is_parent', false)->get(['id', 'code', 'name_ar']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:bank,cash',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:100',
            'iban' => 'nullable|string|max:50',
            'currency' => 'required|string|max:10',
            'opening_balance' => 'required|numeric|min:0',
            'account_id' => 'nullable|tenant_exists:accounts,id',
        ]);

        $box = app(BankAccountService::class)->create($validated);

        if ($json = $this->createdResponse($request, $box, $box->name)) {
            return $json;
        }

        return redirect()->route('finance.banks.index')
            ->with('success', 'تم إضافة الحساب البنكي / الخزينة بنجاح');
    }

    public function edit(BankAccount $bank): Response
    {
        return $this->create()->with('bank', $bank);
    }

    /** Names and bank details; the balance and ledger account follow the postings. */
    public function update(Request $request, BankAccount $bank)
    {
        $bank->update($request->validate([
            'name' => 'required|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:100',
            'iban' => 'nullable|string|max:50',
        ]));

        return redirect()->route('finance.banks.index')->with('success', 'تم تعديل بيانات الحساب');
    }

    public function destroy(BankAccount $bank)
    {
        if (abs($bank->ledgerBalance()) > 0.009) {
            return back()->with('error', 'لا يمكن حذف حساب يحتوي على رصيد. قم بتحويل الرصيد أولاً.');
        }

        // Archived (soft deleted): historic vouchers keep resolving it.
        $bank->delete();

        return redirect()->route('finance.banks.index')
            ->with('success', 'تم حذف الحساب بنجاح');
    }
}

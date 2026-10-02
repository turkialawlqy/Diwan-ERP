<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankAccountController extends Controller
{
    public function index(): Response
    {
        $accounts = BankAccount::with('account')->latest()->get();

        $stats = [
            'total_bank_balance' => BankAccount::where('type', 'bank')->sum('current_balance'),
            'total_cash_balance' => BankAccount::where('type', 'cash')->sum('current_balance'),
            'total_liquidity' => BankAccount::sum('current_balance'),
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

        BankAccount::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'bank_name' => $validated['bank_name'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'iban' => $validated['iban'] ?? null,
            'currency' => $validated['currency'],
            'opening_balance' => $validated['opening_balance'],
            'current_balance' => $validated['opening_balance'],
            'account_id' => $validated['account_id'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->route('finance.banks.index')
            ->with('success', 'تم إضافة الحساب البنكي / الخزينة بنجاح');
    }

    public function destroy(BankAccount $bank)
    {
        $bank->delete();

        return redirect()->route('finance.banks.index')
            ->with('success', 'تم حذف الحساب بنجاح');
    }
}

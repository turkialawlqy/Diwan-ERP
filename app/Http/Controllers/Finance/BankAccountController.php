<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DocumentPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($validated) {
            $box = BankAccount::create([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'bank_name' => $validated['bank_name'] ?? null,
                'account_number' => $validated['account_number'] ?? null,
                'iban' => $validated['iban'] ?? null,
                'currency' => $validated['currency'],
                'opening_balance' => $validated['opening_balance'],
                'current_balance' => $validated['opening_balance'],
                // Every box gets its own ledger account (needed for statements and reconciliation).
                'account_id' => $validated['account_id'] ?? $this->createLedgerAccount($validated['name'], $validated['type'])->id,
                'is_active' => true,
            ]);

            app(DocumentPoster::class)->openingBalance(
                $box,
                ['account_id' => $box->account_id, 'side' => 'debit'],
                (float) $validated['opening_balance'],
                now(),
                'رصيد افتتاحي - '.$box->name,
            );
        });

        return redirect()->route('finance.banks.index')
            ->with('success', 'تم إضافة الحساب البنكي / الخزينة بنجاح');
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

    /**
     * Leaf ledger account for a new cash box / bank under the cash group.
     */
    protected function createLedgerAccount(string $name, string $type): Account
    {
        $map = app(AccountMap::class);
        $default = Account::find($map->id($type === 'cash' ? 'default_cash' : 'default_bank'));
        $parent = $default?->parent;

        $prefix = $parent?->code ?? '111';
        $siblings = Account::where('parent_id', $parent?->id)->pluck('code');
        $next = 1;
        while ($siblings->contains($prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT)) || Account::where('code', $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT))->exists()) {
            $next++;
        }

        return Account::create([
            'parent_id' => $parent?->id,
            'code' => $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT),
            'name_ar' => $name,
            'type' => 'asset',
            'nature' => 'debit',
            'is_parent' => false,
            'balance' => 0,
            'is_active' => true,
            'cash_flow_category' => null,
        ]);
    }
}

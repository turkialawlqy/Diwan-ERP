<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\PosRegister;
use App\Services\Accounting\AccountMap;
use App\Services\ActiveBranch;
use App\Services\Finance\BankAccountService;
use Closure;
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

    public function create(): Response
    {
        return $this->form();
    }

    protected function form(?BankAccount $bank = null): Response
    {
        return Inertia::render('Finance/Banks/Form', [
            'chartAccounts' => $this->linkableAccounts($bank),
            'ledgerParents' => $this->ledgerParents(),
            'bank' => $bank,
        ]);
    }

    /** The group a box's own ledger opens under, by box type (BankAccountService::createLedgerAccount): one made by hand from the form goes there too. */
    protected function ledgerParents(): array
    {
        $roles = app(AccountMap::class)->all();
        $parents = Account::whereIn('id', array_filter([$roles['default_cash'] ?? null, $roles['default_bank'] ?? null]))->pluck('parent_id', 'id');

        return [
            'cash' => $parents[$roles['default_cash'] ?? 0] ?? null,
            'bank' => $parents[$roles['default_bank'] ?? 0] ?? null,
        ];
    }

    /** Asset leaves a new box may be linked to (BankAccount::linkableLedgers; store() refuses the others). */
    protected function linkableAccounts(?BankAccount $bank = null)
    {
        return BankAccount::linkableLedgers($bank)->orderBy('code')->get(['id', 'code', 'name_ar']);
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
            // Empty: the box gets a ledger account of its own (the form's default), the only way to keep it apart from other branches.
            'account_id' => ['nullable', 'tenant_exists:accounts,id', fn (string $attribute, mixed $value, Closure $fail) => $this->checkLedger((int) $value, $fail)],
        ]);

        $box = app(BankAccountService::class)->create($validated);

        if ($json = $this->createdResponse($request, $box, $box->name)) {
            return $json;
        }

        return redirect()->route('finance.banks.index')
            ->with('success', 'تم إضافة الحساب البنكي / الخزينة بنجاح');
    }

    /**
     * A chosen ledger account becomes this box's balance, so it is one nobody else posts to: an asset leaf that is not an
     * account the system posts to for every branch (default cash, input VAT... AccountMap), not another box's ledger
     * (of any branch: one ledger, one box) and without postings of another branch. A box without a branch (a company
     * that has none) may take any asset leaf, as before.
     */
    protected function checkLedger(int $accountId, Closure $fail): void
    {
        $account = Account::find($accountId);
        if (! $account || $account->type !== 'asset' || $account->is_parent) {
            $fail('اختر حسابًا فرعيًا من الأصول، أو اتركه فارغًا لينشأ للصندوق حساب خاص به.');

            return;
        }
        // A switched-off account, or one of credit nature (an allowance, accumulated depreciation), is no box's balance.
        if (! $account->is_active || $account->nature !== 'debit') {
            $fail('هذا الحساب موقوف أو ليس من طبيعة مدينة؛ اختر غيره أو اتركه فارغًا لينشأ للصندوق حساب خاص به.');

            return;
        }
        // Vouchers, transfers and POS post to a box's ledger without a cost center: each would be refused.
        if ($account->requires_cost_center) {
            $fail('هذا الحساب يتطلب مركز تكلفة، والنظام يرحّل على الصندوق بلا مركز تكلفة؛ اختر غيره أو اتركه فارغًا لينشأ للصندوق حساب خاص به.');

            return;
        }
        $branch = app(ActiveBranch::class)->forWrite();
        if (! $branch) {
            return;
        }
        if (in_array($accountId, array_map('intval', array_filter(app(AccountMap::class)->all())), true)) {
            $fail('يرحّل النظام على هذا الحساب من كل الفروع (مثل الصندوق أو البنك الافتراضي)؛ اتركه فارغًا لينشأ للصندوق حساب خاص به.');
        } elseif (BankAccount::boxLedgers([$accountId])) {
            $fail('هذا الحساب مربوط بصندوق أو بنك آخر؛ اتركه فارغًا لينشأ للصندوق حساب خاص به.');
        } elseif (in_array($accountId, BankAccount::configuredAccounts($branch), true)) {
            $fail('يرحّل النظام على هذا الحساب من إعدادات أخرى (مجموعة عملاء أو فئة أصول أو قيد متكرر لفرع آخر)؛ اتركه فارغًا لينشأ للصندوق حساب خاص به.');
        } elseif (DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $account->company_id)->where('l.account_id', $accountId)->whereNotNull('e.branch_id')->where('e.branch_id', '!=', $branch)->exists()) {
            $fail('على هذا الحساب حركات فرع آخر؛ اتركه فارغًا لينشأ للصندوق حساب خاص به.');
        }
    }

    public function edit(BankAccount $bank): Response
    {
        return $this->form($bank);
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
        // An archived box takes no more cash: a register still counting into it would stop selling for cash.
        if (PosRegister::withoutGlobalScope('branch')->where('bank_account_id', $bank->id)->where('is_active', true)->exists()) {
            return back()->with('error', 'هذا الصندوق درج نقطة بيع نشطة؛ اربط نقطة البيع بصندوق آخر أو أوقفها قبل حذفه.');
        }

        // Archived (soft deleted): historic vouchers keep resolving it.
        $bank->delete();

        return redirect()->route('finance.banks.index')
            ->with('success', 'تم حذف الحساب بنجاح');
    }
}

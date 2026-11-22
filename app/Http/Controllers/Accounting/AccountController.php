<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Services\Accounting\AccountMap;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Account::with(['parent'])->withCount(['children', 'journalLines'])->orderBy('code');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $accounts = $query->get();

        $stats = [
            'total_assets' => Account::where('type', 'asset')->where('is_parent', false)->sum('balance'),
            'total_liabilities' => Account::where('type', 'liability')->where('is_parent', false)->sum('balance'),
            'total_equity' => Account::where('type', 'equity')->where('is_parent', false)->sum('balance'),
            'total_revenues' => Account::where('type', 'revenue')->where('is_parent', false)->sum('balance'),
            'total_expenses' => Account::where('type', 'expense')->where('is_parent', false)->sum('balance'),
            'total_accounts_count' => Account::count(),
        ];

        $parentAccounts = Account::where('is_parent', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'type']);

        return Inertia::render('Accounting/Accounts/Index', [
            'accounts' => $accounts,
            'parentAccounts' => $parentAccounts,
            'stats' => $stats,
            'filters' => $request->only(['search', 'type']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Accounting/Accounts/Form', $this->lookups() + ['parentId' => (int) $request->query('parent_id') ?: '']);
    }

    public function edit(Account $account): Response
    {
        return Inertia::render('Accounting/Accounts/Form', $this->lookups() + ['account' => $account]);
    }

    protected function lookups(): array
    {
        return [
            'accounts' => Account::orderBy('code')->get(['id', 'code', 'name_ar', 'type', 'nature', 'parent_id', 'is_parent']),
            'parentAccounts' => Account::where('is_parent', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'type']),
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', $this->uniqueCode()],
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'parent_id' => 'nullable|tenant_exists:accounts,id',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'nature' => 'required|in:debit,credit',
            'is_parent' => 'boolean',
            'cash_flow_category' => 'nullable|in:operating,investing,financing',
            'requires_cost_center' => 'boolean',
        ], $this->messages());

        if (! empty($validated['parent_id'])) {
            $parent = Account::find($validated['parent_id']);
            if ($parent && ! $parent->is_parent) {
                // A posting account becomes a group under its first child: as in update(), not one with postings, nor one
                // the system posts to (a box's ledger, a mapped role), whose postings would all be refused from then on.
                if ($problem = $this->groupProblem($parent)) {
                    throw ValidationException::withMessages(['parent_id' => $problem]);
                }
                $parent->update(['is_parent' => true]);
            }
        }

        $account = Account::create([
            'code' => $validated['code'],
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?? null,
            'parent_id' => $validated['parent_id'] ?? null,
            'type' => $validated['type'],
            'nature' => $validated['nature'],
            'is_parent' => $validated['is_parent'] ?? false,
            // Opening balances are entered as opening journal entries, never typed in.
            'balance' => 0.00,
            'is_active' => true,
            'cash_flow_category' => $validated['cash_flow_category'] ?? null,
            'requires_cost_center' => $validated['requires_cost_center'] ?? false,
        ]);

        if ($json = $this->createdResponse($request, $account, $account->code.' - '.$account->name_ar)) {
            return $json;
        }

        return redirect()->route('accounting.accounts.index')
            ->with('success', 'تم إضافة الحساب المحاسبي بنجاح');
    }

    public function update(Request $request, Account $account)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', $this->uniqueCode($account)],
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'parent_id' => 'nullable|tenant_exists:accounts,id',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'nature' => 'required|in:debit,credit',
            'is_parent' => 'boolean',
            'is_active' => 'boolean',
            'cash_flow_category' => 'nullable|in:operating,investing,financing',
            'requires_cost_center' => 'boolean',
        ], $this->messages());
        $retyped = $validated['type'] !== $account->type || $validated['nature'] !== $account->nature;

        // An account that already has postings cannot become a group account.
        if (! empty($validated['is_parent']) && ! $account->is_parent && ($problem = $this->groupProblem($account))) {
            return back()->with('error', $problem);
        }
        // Nor does a group keep sub-accounts as a posting account: they would roll up into an account with postings of its own.
        if (array_key_exists('is_parent', $validated) && ! $validated['is_parent'] && $account->is_parent && $account->children()->exists()) {
            return back()->with('error', 'لا يمكن تحويل حساب رئيسي له حسابات فرعية إلى حساب فرعي. انقل حساباته الفرعية أولًا.');
        }

        // Postings were classified under the current type and nature; changing them would move history between statements.
        if ($retyped && $account->journalLines()->exists()) {
            return back()->with('error', 'لا يمكن تغيير نوع أو طبيعة حساب عليه حركات. أنشئ حساباً جديداً وانقل الرصيد بقيد تسوية.');
        }

        // A box's ledger is that box's balance: it stays an asset of debit nature (a revenue voucher would credit it), and
        // a box of a branch the user may not work in is not theirs to change at all: renamed, recoded, moved, switched
        // off or made to need a cost center (which refuses every posting to it just the same).
        if (BankAccount::boxLedgers([$account->id])) {
            if ($retyped) {
                return back()->with('error', 'هذا الحساب حساب صندوق أو بنك؛ لا يتغير نوعه ولا طبيعته.');
            }
            if (BankAccount::hiddenLedgers([$account->id]) && (clone $account)->fill($validated)->isDirty()) {
                return back()->with('error', 'هذا الحساب مربوط بصندوق أو بنك لفرع آخر.');
            }
        }

        // Switched off, an account the system posts to refuses each such posting («موقوف»); retyped, it moves them to
        // another statement. Only the ledger of boxes in the user's own branches is theirs to switch off.
        $switchedOff = array_key_exists('is_active', $validated) && ! $validated['is_active'] && $account->is_active;
        if (($switchedOff || $retyped) && ($problem = $this->sharedProblem($account))) {
            return back()->with('error', $problem);
        }

        if (! empty($validated['requires_cost_center']) && ! $account->requires_cost_center && $this->postedWithoutCostCenter($account)) {
            return back()->with('error', 'يرحّل النظام على حسابات الصناديق والبنوك بلا مركز تكلفة؛ لا يُشترط لهذا الحساب مركز تكلفة.');
        }

        // The balance is derived from posted journal lines and is never edited directly.

        // Prevent making account its own parent
        if (! empty($validated['parent_id']) && (int) $validated['parent_id'] === (int) $account->id) {
            return back()->with('error', 'لا يمكن جعل الحساب تابعاً لنفسه');
        }

        // A new parent is a group account, or becomes one as in store(): under a posting account the trial balance
        // would roll this account up into one with postings of its own. Never one of its own sub-accounts (a loop).
        $parent = null;
        if (! empty($validated['parent_id']) && (int) $validated['parent_id'] !== (int) $account->parent_id) {
            if ($this->isUnder((int) $validated['parent_id'], $account)) {
                return back()->with('error', 'لا يمكن جعل الحساب تابعاً لأحد حساباته الفرعية.');
            }
            $parent = Account::find($validated['parent_id']);
            if ($parent && ! $parent->is_parent && ($problem = $this->groupProblem($parent))) {
                return back()->with('error', $problem);
            }
        }

        DB::transaction(function () use ($account, $validated, $parent) {
            if ($parent && ! $parent->is_parent) {
                $parent->update(['is_parent' => true]);
            }
            $account->update($validated);
        });

        return redirect()->route('accounting.accounts.index')
            ->with('success', 'تم تحديث بيانات الحساب بنجاح');
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return ['code.unique' => 'رقم الحساب مستخدم لحساب آخر في الدليل.'];
    }

    /** A code names one account of the company's chart (the database refuses a second one, as a server error). */
    protected function uniqueCode(?Account $account = null): Unique
    {
        return Rule::unique('accounts', 'code')->where('company_id', app(TenantManager::class)->getTenantId())->ignore($account?->id);
    }

    /**
     * Why $account may not be switched off or retyped, or null: the system posts to it for others than the user's own
     * branches (isPostedBySystem). Every branch posts to a role account (box-less vouchers and POS to the default cash,
     * every sale to output VAT...) and to the ledger of a box shared by all branches; a ledger shared with another
     * branch's box moves that box too. The ledger of boxes in the user's own branches only is theirs.
     */
    protected function sharedProblem(Account $account): ?string
    {
        if (! $account->isPostedBySystem()) {
            return null;
        }
        if (in_array((int) $account->id, array_map('intval', array_filter(app(AccountMap::class)->all())), true)) {
            return 'هذا الحساب مربوط في «ربط الحسابات» ويرحّل عليه النظام لكل الفروع؛ لا يُوقف ولا يتغير نوعه أو طبيعته. اربط الدور بحساب آخر من «ربط الحسابات» أولًا.';
        }
        $branches = BankAccount::ledgerBranches([$account->id])->get((int) $account->id, collect());
        if ($branches->containsStrict(null)) {
            return 'هذا الحساب حساب صندوق أو بنك مشترك بين كل الفروع؛ لا يُوقف.';
        }
        $allowed = auth()->user()?->allowedBranchIds();

        return $allowed !== null && $branches->diff($allowed)->isNotEmpty() ? 'هذا الحساب مربوط بصندوق أو بنك لفرع آخر.' : null;
    }

    /** Whether the account $accountId sits somewhere under $account: placed under it, $account would be its own ancestor. */
    protected function isUnder(int $accountId, Account $account): bool
    {
        $seen = [];
        for ($id = $accountId; $id && ! isset($seen[$id]); $id = (int) Account::whereKey($id)->value('parent_id')) {
            $seen[$id] = true;
            if ($id === (int) $account->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * A box's ledger, or a cash role a voucher without a box falls back on (default cash, bank, petty cash): vouchers,
     * transfers and POS post to it without a cost center, so requiring one would refuse every such posting.
     */
    protected function postedWithoutCostCenter(Account $account): bool
    {
        $cashRoles = array_intersect_key(app(AccountMap::class)->all(), array_flip(AccountMap::CASH_ROLES));

        return BankAccount::boxLedgers([$account->id]) !== []
            || in_array((int) $account->id, array_map('intval', array_filter($cashRoles)), true);
    }

    /** Why $account cannot become a group account, or null: its postings, its box, or a role mapped to it. */
    protected function groupProblem(Account $account): ?string
    {
        if ($account->journalLines()->exists()) {
            return 'لا يمكن تحويل حساب عليه حركات إلى حساب رئيسي.';
        }
        if ($account->isPostedBySystem()) {
            return 'لا يمكن تحويل حساب يرحّل عليه النظام (صندوق أو بنك أو حساب مربوط في ربط الحسابات) إلى حساب رئيسي.';
        }

        return null;
    }

    public function destroy(Account $account)
    {
        if ($account->children()->exists()) {
            return back()->with('error', 'لا يمكن حذف الحساب لأنه يحتوي على حسابات فرعية متفرعة منه. يجب نقلها أو حذفها أولاً.');
        }

        if ($account->journalLines()->exists()) {
            return back()->with('error', 'لا يمكن حذف الحساب نظراً لوجود قيود يومية وعمليات محاسبية مسجلة عليه.');
        }

        // Without its ledger a box would post to the default cash / bank account.
        if (BankAccount::boxLedgers([$account->id])) {
            return back()->with('error', 'لا يمكن حذف حساب مربوط بصندوق أو بنك.');
        }

        // Nor is a role account deleted (AccountMap would find none): box-less vouchers would have no cash account, and
        // a new box no group to open its ledger under (BankAccountService::createLedgerAccount).
        if ($account->isPostedBySystem()) {
            return back()->with('error', 'هذا الحساب مربوط في «ربط الحسابات» ويرحّل عليه النظام؛ لا يُحذف. اربط الدور بحساب آخر من «ربط الحسابات» أولًا.');
        }

        $account->delete();

        return redirect()->route('accounting.accounts.index')
            ->with('success', 'تم حذف الحساب بنجاح');
    }
}

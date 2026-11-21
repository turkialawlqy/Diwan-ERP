<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Services\Accounting\AccountMap;
use Illuminate\Http\Request;
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
            'code' => 'required|string|max:50',
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'parent_id' => 'nullable|tenant_exists:accounts,id',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'nature' => 'required|in:debit,credit',
            'is_parent' => 'boolean',
            'cash_flow_category' => 'nullable|in:operating,investing,financing',
            'requires_cost_center' => 'boolean',
        ]);

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
            'code' => 'required|string|max:50',
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'parent_id' => 'nullable|tenant_exists:accounts,id',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'nature' => 'required|in:debit,credit',
            'is_parent' => 'boolean',
            'is_active' => 'boolean',
            'cash_flow_category' => 'nullable|in:operating,investing,financing',
            'requires_cost_center' => 'boolean',
        ]);

        // An account that already has postings cannot become a group account.
        if (! empty($validated['is_parent']) && ! $account->is_parent && ($problem = $this->groupProblem($account))) {
            return back()->with('error', $problem);
        }

        // Postings were classified under the current type and nature; changing them would move history between statements.
        if (($validated['type'] !== $account->type || $validated['nature'] !== $account->nature) && $account->journalLines()->exists()) {
            return back()->with('error', 'لا يمكن تغيير نوع أو طبيعة حساب عليه حركات. أنشئ حساباً جديداً وانقل الرصيد بقيد تسوية.');
        }

        // A box's ledger is that box's balance: it stays an asset of debit nature (a revenue voucher would credit it), and
        // a box of a branch the user may not work in is not theirs to move or switch off.
        if (BankAccount::boxLedgers([$account->id])) {
            if ($validated['type'] !== $account->type || $validated['nature'] !== $account->nature) {
                return back()->with('error', 'هذا الحساب حساب صندوق أو بنك؛ لا يتغير نوعه ولا طبيعته.');
            }
            $moved = (array_key_exists('parent_id', $validated) && (int) $validated['parent_id'] !== (int) $account->parent_id)
                || (array_key_exists('is_active', $validated) && (bool) $validated['is_active'] !== (bool) $account->is_active);
            if ($moved && BankAccount::hiddenLedgers([$account->id])) {
                return back()->with('error', 'هذا الحساب مربوط بصندوق أو بنك لفرع آخر.');
            }
        }

        // The balance is derived from posted journal lines and is never edited directly.

        // Prevent making account its own parent
        if (! empty($validated['parent_id']) && (int) $validated['parent_id'] === (int) $account->id) {
            return back()->with('error', 'لا يمكن جعل الحساب تابعاً لنفسه');
        }

        $account->update($validated);

        return redirect()->route('accounting.accounts.index')
            ->with('success', 'تم تحديث بيانات الحساب بنجاح');
    }

    /** Why $account cannot become a group account, or null: its postings, its box, or a role mapped to it. */
    protected function groupProblem(Account $account): ?string
    {
        if ($account->journalLines()->exists()) {
            return 'لا يمكن تحويل حساب عليه حركات إلى حساب رئيسي.';
        }
        if (BankAccount::boxLedgers([$account->id]) || in_array((int) $account->id, array_map('intval', array_filter(app(AccountMap::class)->all())), true)) {
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

        $account->delete();

        return redirect()->route('accounting.accounts.index')
            ->with('success', 'تم حذف الحساب بنجاح');
    }
}

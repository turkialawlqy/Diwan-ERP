<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;
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
                $parent->update(['is_parent' => true]);
            }
        }

        Account::create([
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
        if (! empty($validated['is_parent']) && ! $account->is_parent && $account->journalLines()->exists()) {
            return back()->with('error', 'لا يمكن تحويل حساب عليه حركات إلى حساب رئيسي.');
        }

        // Postings were classified under the current type and nature; changing them would move history between statements.
        if (($validated['type'] !== $account->type || $validated['nature'] !== $account->nature) && $account->journalLines()->exists()) {
            return back()->with('error', 'لا يمكن تغيير نوع أو طبيعة حساب عليه حركات. أنشئ حساباً جديداً وانقل الرصيد بقيد تسوية.');
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

    public function destroy(Account $account)
    {
        if ($account->children()->exists()) {
            return back()->with('error', 'لا يمكن حذف الحساب لأنه يحتوي على حسابات فرعية متفرعة منه. يجب نقلها أو حذفها أولاً.');
        }

        if ($account->journalLines()->exists()) {
            return back()->with('error', 'لا يمكن حذف الحساب نظراً لوجود قيود يومية وعمليات محاسبية مسجلة عليه.');
        }

        $account->delete();

        return redirect()->route('accounting.accounts.index')
            ->with('success', 'تم حذف الحساب بنجاح');
    }
}

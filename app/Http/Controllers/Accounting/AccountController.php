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
        $query = Account::with(['parent', 'children'])->orderBy('code');

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

        $parentAccounts = Account::where('is_parent', true)->get(['id', 'code', 'name_ar', 'type']);

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
            'parent_id' => 'nullable|exists:accounts,id',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'nature' => 'required|in:debit,credit',
            'is_parent' => 'boolean',
            'balance' => 'nullable|numeric',
        ]);

        Account::create([
            'code' => $validated['code'],
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?? null,
            'parent_id' => $validated['parent_id'] ?? null,
            'type' => $validated['type'],
            'nature' => $validated['nature'],
            'is_parent' => $validated['is_parent'] ?? false,
            'balance' => $validated['balance'] ?? 0.00,
            'is_active' => true,
        ]);

        return redirect()->route('accounting.accounts.index')
            ->with('success', 'تم إضافة الحساب المحاسبي بنجاح');
    }
}

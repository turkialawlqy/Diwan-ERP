<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounting\AccountMap;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which ledger account the system posts to for each operation.
 */
class AccountMappingController extends Controller
{
    public function index(AccountMap $map): Response
    {
        return Inertia::render('Accounting/Mappings/Index', [
            'roles' => collect(AccountMap::ROLES)->map(fn ($r, $key) => ['key' => $key, 'label' => $r[0], 'type' => $r[1]])->values(),
            'mapping' => $map->all(),
            'accounts' => Account::where('is_parent', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'type']),
        ]);
    }

    public function update(Request $request, AccountMap $map)
    {
        $validated = $request->validate([
            'mapping' => 'required|array',
            'mapping.*' => 'nullable|tenant_exists:accounts,id',
        ]);

        $accounts = Account::whereIn('id', array_filter($validated['mapping']))->get()->keyBy('id');
        $companyId = app(TenantManager::class)->getTenantId();

        foreach ($validated['mapping'] as $role => $accountId) {
            if (! array_key_exists($role, AccountMap::ROLES)) {
                continue;
            }

            $account = $accountId ? $accounts->get($accountId) : null;
            if ($account && $account->is_parent) {
                throw ValidationException::withMessages(["mapping.{$role}" => 'يجب اختيار حساب فرعي.']);
            }
            if ($account && $account->type !== AccountMap::ROLES[$role][1]) {
                $types = ['asset' => 'أصول', 'liability' => 'خصوم', 'equity' => 'حقوق ملكية', 'revenue' => 'إيرادات', 'expense' => 'مصروفات'];
                throw ValidationException::withMessages(["mapping.{$role}" => 'حساب «'.AccountMap::ROLES[$role][0].'» يجب أن يكون من نوع '.$types[AccountMap::ROLES[$role][1]].'.']);
            }

            $map->set($role, $account?->id, $companyId);
        }

        return back()->with('success', 'تم حفظ ربط الحسابات');
    }
}

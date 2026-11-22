<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DimensionPolicy;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which ledger account the system posts to for each operation.
 */
class AccountMappingController extends Controller
{
    public function index(AccountMap $map, DimensionPolicy $policy): Response
    {
        return Inertia::render('Accounting/Mappings/Index', [
            'dimensionPolicy' => $policy->modes(),
            'roles' => collect(AccountMap::ROLES)->map(fn ($r, $key) => ['key' => $key, 'label' => $r[0], 'type' => $r[1]])->values(),
            'mapping' => $map->all(),
            'accounts' => Account::where('is_parent', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'type']),
        ]);
    }

    /** Cost-centre / project policy (optional, required, or only on profit-and-loss accounts). */
    public function dimensions(Request $request, DimensionPolicy $policy)
    {
        $validated = $request->validate([
            'cost_center' => ['required', Rule::in(DimensionPolicy::MODES)],
            'project' => ['required', Rule::in(DimensionPolicy::MODES)],
        ]);
        $companyId = app(TenantManager::class)->getTenantId();
        foreach (['cost_center', 'project'] as $dimension) {
            $policy->set($dimension, $validated[$dimension], $companyId);
        }

        return back()->with('success', 'تم حفظ سياسة مراكز التكلفة والمشاريع');
    }

    public function update(Request $request, AccountMap $map)
    {
        $validated = $request->validate([
            'mapping' => 'required|array',
            'mapping.*' => 'nullable|tenant_exists:accounts,id',
        ]);

        $accounts = Account::whereIn('id', array_filter($validated['mapping']))->get()->keyBy('id');
        $companyId = app(TenantManager::class)->getTenantId();

        // Checked first, saved together: a refused role leaves the others as they were.
        $roles = array_intersect_key($validated['mapping'], AccountMap::ROLES);
        foreach ($roles as $role => $accountId) {
            if ($problem = $map->problem($role, $accountId ? $accounts->get($accountId) : null, $companyId)) {
                throw ValidationException::withMessages(["mapping.{$role}" => $problem]);
            }
        }
        DB::transaction(function () use ($roles, $accounts, $map, $companyId) {
            foreach ($roles as $role => $accountId) {
                $map->set($role, $accountId ? $accounts->get($accountId)?->id : null, $companyId);
            }
        });

        return back()->with('success', 'تم حفظ ربط الحسابات');
    }
}

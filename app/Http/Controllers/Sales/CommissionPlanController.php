<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\CommissionPlan;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Commission plans and who follows them. */
class CommissionPlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Sales/Commissions/Plans', [
            'plans' => CommissionPlan::withCount('users')->orderBy('name')->get(),
            'types' => CommissionPlan::TYPES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Commissions/PlanForm', ['plan' => null, 'assigned' => []] + $this->lookups());
    }

    public function edit(CommissionPlan $plan): Response
    {
        return Inertia::render('Sales/Commissions/PlanForm', ['plan' => $plan, 'assigned' => $plan->users()->pluck('id')] + $this->lookups());
    }

    public function store(Request $request)
    {
        [$data, $users] = $this->validated($request);
        $plan = CommissionPlan::create($data);
        $this->assign($plan, $users);

        if ($json = $this->createdResponse($request, $plan, $plan->name)) {
            return $json;
        }

        return redirect()->route('sales.commission-plans.index')->with('success', 'تمت إضافة خطة العمولة');
    }

    public function update(Request $request, CommissionPlan $plan)
    {
        [$data, $users] = $this->validated($request);
        $plan->update($data);
        $this->assign($plan, $users);

        return redirect()->route('sales.commission-plans.index')->with('success', 'تم تحديث خطة العمولة');
    }

    public function destroy(CommissionPlan $plan)
    {
        $plan->delete(); // users fall back to their own flat rate

        return redirect()->route('sales.commission-plans.index')->with('success', 'تم حذف الخطة');
    }

    protected function lookups(): array
    {
        return [
            'types' => CommissionPlan::TYPES,
            'users' => User::where('company_id', app(TenantManager::class)->getTenantId())->where('is_super_admin', false)->orderBy('name')->get(['id', 'name', 'commission_plan_id']),
        ];
    }

    protected function assign(CommissionPlan $plan, array $userIds): void
    {
        $companyId = $plan->company_id;
        User::where('company_id', $companyId)->where('commission_plan_id', $plan->id)->whereNotIn('id', $userIds)->update(['commission_plan_id' => null]);
        User::where('company_id', $companyId)->whereIn('id', $userIds)->update(['commission_plan_id' => $plan->id]);
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_keys(CommissionPlan::TYPES))],
            'rate' => 'nullable|numeric|min:0|max:100|required_if:type,flat',
            'tiers' => 'nullable|array|max:10|required_if:type,tiered',
            'tiers.*.from' => 'required|numeric|min:0',
            'tiers.*.rate' => 'required|numeric|min:0|max:100',
            'target_amount' => 'nullable|numeric|min:0',
            'bonus_amount' => 'nullable|numeric|min:0|required_with:target_amount',
            'is_active' => 'boolean',
            'users' => 'nullable|array',
            'users.*' => ['integer', Rule::exists('users', 'id')->where('company_id', app(TenantManager::class)->getTenantId())],
        ]);
        $users = $data['users'] ?? [];
        unset($data['users']);
        $data['rate'] = $data['rate'] ?? 0;
        $data['tiers'] = $data['type'] === 'tiered' ? collect($data['tiers'])->sortBy('from')->values()->all() : null;
        $data['is_active'] = $request->boolean('is_active', true);

        return [$data, $users];
    }
}

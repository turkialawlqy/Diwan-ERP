<?php

namespace App\Services\Saas;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * Subscription state, plan modules and plan limits of a company.
 */
class PlanGuard
{
    /** resource => [plan column, label, counter] */
    protected function limits(): array
    {
        return [
            'users' => ['max_users', 'المستخدمين', fn (Company $c) => User::where('company_id', $c->id)->where('is_super_admin', false)->count()],
            'branches' => ['max_branches', 'الفروع', fn (Company $c) => Branch::withoutGlobalScopes()->where('company_id', $c->id)->whereNull('deleted_at')->count()],
            'warehouses' => ['max_warehouses', 'المستودعات', fn (Company $c) => Warehouse::withoutGlobalScopes()->where('company_id', $c->id)->whereNull('deleted_at')->count()],
            'employees' => ['max_employees', 'الموظفين', fn (Company $c) => Employee::withoutGlobalScopes()->where('company_id', $c->id)->whereNull('deleted_at')->where('status', '!=', 'terminated')->count()],
            'invoices' => ['max_invoices_per_month', 'الفواتير الشهرية', fn (Company $c) => Invoice::withoutGlobalScopes()->where('company_id', $c->id)
                ->where('created_at', '>=', now()->startOfMonth())->count()],
        ];
    }

    public function hasModule(Company $company, string $module): bool
    {
        return ! $company->plan || $company->plan->includesModule($module);
    }

    public function moduleForRoute(?string $routeName): ?string
    {
        foreach (config('saas.route_modules') as $prefix => $module) {
            if ($routeName && str_starts_with($routeName, $prefix)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * trial | active | grace | expired
     */
    public function state(Company $company): string
    {
        if ($company->status === 'trial') {
            return ! $company->trial_ends_at || $company->trial_ends_at->isFuture() ? 'trial' : $this->lapsed($company->trial_ends_at);
        }

        if (! $company->subscription_ends_at || $company->subscription_ends_at->isFuture()) {
            return 'active';
        }

        return $this->lapsed($company->subscription_ends_at);
    }

    protected function lapsed($endedAt): string
    {
        return $endedAt->copy()->addDays(config('saas.grace_days', 7))->isFuture() ? 'grace' : 'expired';
    }

    /**
     * Throw when creating one more $resource would exceed the plan.
     */
    public function assertCanAdd(Company $company, string $resource): void
    {
        [$column, $label, $counter] = $this->limits()[$resource];
        $max = $company->plan?->{$column};
        if ($max === null || $max < 0) {
            return; // unlimited
        }

        if ($counter($company) >= $max) {
            throw ValidationException::withMessages([
                'plan' => "بلغت الحد الأقصى لـ{$label} في باقتك الحالية ({$max}). قم بالترقية من صفحة الاشتراك.",
            ]);
        }
    }

    public function usage(Company $company): array
    {
        $out = [];
        foreach ($this->limits() as $resource => [$column, $label, $counter]) {
            $out[$resource] = ['label' => $label, 'used' => $counter($company), 'max' => $company->plan?->{$column}];
        }

        return $out;
    }
}

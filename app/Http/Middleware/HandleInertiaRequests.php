<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Currency;
use App\Services\Saas\PlanGuard;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Support\CurrencyNames;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $tenantManager = app(TenantManager::class);
        $currentTenant = $tenantManager->getTenant();
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'avatar' => $user->avatar,
                    'is_super_admin' => (bool) $user->is_super_admin,
                    'roles' => $currentTenant ? $user->getRoleNames() : [],
                    // Owners (and impersonating super admins) can do everything; the
                    // frontend checks is_owner before the permission list.
                    'is_owner' => $currentTenant && ($user->isCompanyOwner() || ($user->isSuperAdmin() && $tenantManager->isImpersonating())),
                    'permissions' => $currentTenant ? $user->getAllPermissions()->pluck('name')->values() : [],
                    'company_id' => $user->company_id,
                    'branch_id' => $user->branch_id,
                    // Branches the user works in (null: all) and the one new documents start on.
                    'branch_ids' => $currentTenant && $user->company_id ? $user->allowedBranchIds() : null,
                    'default_branch_id' => $currentTenant && $user->company_id ? $user->defaultBranchId() : null,
                ] : null,
            ],
            'tenant' => $currentTenant ? [
                'id' => $currentTenant->id,
                'name' => $currentTenant->name,
                'subdomain' => $currentTenant->subdomain,
                'logo' => $currentTenant->logo_url,
                'currency' => $currentTenant->currency ?? 'SAR',
                'country' => $currentTenant->country_code ?? 'SA',
                'currency_symbol' => CurrencyNames::for($currentTenant->currency)[2],
                // Countries the company operates in (its branches'): country-specific menus.
                'countries' => Branch::query()->pluck('country_code')->map(fn ($c) => $c ?: ($currentTenant->country_code ?: 'SA'))
                    ->push($currentTenant->country_code ?: 'SA')->unique()->values()->all(),
                'language' => $currentTenant->language ?? 'ar',
                'tax_number' => $currentTenant->tax_number,
                'status' => $currentTenant->status,
                'trial_ends_at' => $currentTenant->trial_ends_at?->format('Y-m-d'),
            ] : null,
            // Tax rules of the user's branch (its country): default rate, allowed rates, currency.
            'tax' => fn () => $currentTenant && $user ? TaxProfile::current()->toArray() : null,
            // Currencies the company uses (forms show a currency picker when > 1).
            'currencies' => fn () => $currentTenant ? Currency::orderByDesc('is_default')->orderBy('code')
                ->get(['code', 'name_ar', 'symbol_ar', 'exchange_rate'])->toArray() : [],
            'impersonation' => $tenantManager->getImpersonationData(),
            'unreadNotifications' => fn () => $user ? $user->unreadNotifications()->count() : 0,
            'subscription' => fn () => $currentTenant ? [
                'state' => app(PlanGuard::class)->state($currentTenant),
                'ends_at' => ($currentTenant->status === 'trial' ? $currentTenant->trial_ends_at : $currentTenant->subscription_ends_at)?->format('Y-m-d'),
            ] : null,
            // Route name => permission map (same one the server enforces) so the UI
            // can hide links the user cannot open.
            'routePermissions' => fn () => $user && $currentTenant ? config('erp_permissions.routes') : [],
            'brand' => [
                'name' => config('app.name'),
                'name_en' => config('app.brand.name_en'),
                'subtitle' => config('app.brand.subtitle'),
                'tagline' => config('app.brand.tagline'),
                'domain' => config('tenancy.central_domain'),
            ],
            'locale' => app()->getLocale(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
                'latest_order' => fn () => $request->session()->get('latest_order'),
            ],
        ];
    }
}

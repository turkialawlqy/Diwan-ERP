<?php

namespace App\Http\Middleware;

use App\Models\Currency;
use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
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
                ] : null,
            ],
            'tenant' => $currentTenant ? [
                'id' => $currentTenant->id,
                'name' => $currentTenant->name,
                'subdomain' => $currentTenant->subdomain,
                'logo' => $currentTenant->logo_url,
                'currency' => $currentTenant->currency ?? 'SAR',
                'language' => $currentTenant->language ?? 'ar',
                'tax_number' => $currentTenant->tax_number,
                'status' => $currentTenant->status,
                'trial_ends_at' => $currentTenant->trial_ends_at?->format('Y-m-d'),
            ] : null,
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

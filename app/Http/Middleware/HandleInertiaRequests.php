<?php

namespace App\Http\Middleware;

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
        $tenantManager = app(\App\Services\TenantManager::class);
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
                    'roles' => $user->roles ? $user->roles->pluck('name') : [],
                    'permissions' => $user->permissions ? $user->permissions->pluck('name') : [],
                    'company_id' => $user->company_id,
                    'branch_id' => $user->branch_id,
                ] : null,
            ],
            'tenant' => $currentTenant ? [
                'id' => $currentTenant->id,
                'name' => $currentTenant->name,
                'subdomain' => $currentTenant->subdomain,
                'logo' => $currentTenant->logo,
                'currency' => $currentTenant->currency ?? 'SAR',
                'language' => $currentTenant->language ?? 'ar',
                'tax_number' => $currentTenant->tax_number,
                'status' => $currentTenant->status,
                'trial_ends_at' => $currentTenant->trial_ends_at?->format('Y-m-d'),
            ] : null,
            'impersonation' => $tenantManager->getImpersonationData(),
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

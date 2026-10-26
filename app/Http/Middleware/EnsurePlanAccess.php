<?php

namespace App\Http\Middleware;

use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the subscription: modules outside the plan are refused, and a
 * lapsed subscription (after the grace period) makes the company read-only
 * except for billing.
 */
class EnsurePlanAccess
{
    public function __construct(protected TenantManager $tenantManager, protected PlanGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = $this->tenantManager->getCompany();
        if (! $company || $this->tenantManager->isImpersonating()) {
            return $next($request);
        }

        $route = $request->route()?->getName();
        $module = $this->guard->moduleForRoute($route);
        if ($module && ! $this->guard->hasModule($company->loadMissing('plan'), $module)) {
            abort(403, 'هذه الوحدة غير مشمولة في باقة اشتراككم. يمكن الترقية من صفحة الاشتراك.');
        }

        if (! $request->isMethodSafe() && $this->guard->state($company) === 'expired') {
            foreach (config('saas.always_writable') as $prefix) {
                if ($route && str_starts_with($route, $prefix)) {
                    return $next($request);
                }
            }
            abort(402, 'انتهى اشتراك المنشأة. البيانات متاحة للعرض فقط حتى تجديد الاشتراك.');
        }

        return $next($request);
    }
}

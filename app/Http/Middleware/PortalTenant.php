<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal requests carry their company in the path (/portal/{company}), so the portal works on a
 * central domain, a subdomain or a custom domain alike. The company must exist, not be suspended and
 * have the portal module; a host that already resolved to another company is refused.
 */
class PortalTenant
{
    public function __construct(protected TenantManager $tenants, protected PlanGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::find((int) $request->route('company'));
        abort_if(! $company || $company->status === 'suspended', 404);

        $resolved = $this->tenants->getTenant();
        abort_if($resolved && (int) $resolved->id !== (int) $company->id, 404);

        $company->loadMissing('plan');
        abort_unless($this->guard->hasModule($company, 'portal'), 404);

        $this->tenants->setTenant($company);
        app()->setLocale($company->language ?? 'ar');
        $request->attributes->set('portal_company', $company);

        return $next($request);
    }
}

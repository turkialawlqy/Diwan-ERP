<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    protected TenantManager $tenantManager;

    public function __construct(TenantManager $tenantManager)
    {
        $this->tenantManager = $tenantManager;
    }

    /**
     * Handle an incoming request and resolve the current tenant.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $company = null;

        // 1. Check if Super Admin is actively impersonating a company
        if ($this->tenantManager->isImpersonating()) {
            $companyId = session('impersonate_company_id');
            $company = Company::find($companyId);
        }

        // 2. Resolve via Subdomain from host (e.g. 'anas' from 'anas.alostaz.io' or 'anas.localhost')
        if (!$company) {
            $subdomain = $this->extractSubdomain($host);
            if ($subdomain && !in_array($subdomain, ['admin', 'api', 'www', 'mail', 'app', 'dashboard', 'localhost', '127'])) {
                $company = Company::where('subdomain', $subdomain)->first();
            }
        }

        // 3. Resolve via Custom Domain (if mapped)
        if (!$company && !in_array($host, ['localhost', '127.0.0.1', 'alostaz.io'])) {
            $company = Company::where('custom_domain', $host)->first();
        }

        // 4. Fallback for Local Dev / Testing ONLY (Header, Query Param, Authenticated User's Company, or first Company)
        if (!$company) {
            if (app()->environment('local', 'testing')) {
                if ($devSubdomain = $request->query('tenant') ?? $request->header('X-Tenant')) {
                    $company = Company::where('subdomain', $devSubdomain)->first();
                } elseif ($request->user() && $request->user()->company_id) {
                    $company = $request->user()->company;
                } else {
                    $company = Company::first();
                }
            } elseif ($request->user() && $request->user()->company_id) {
                // In production, only resolve from authenticated user's company
                $company = $request->user()->company;
            }
        }

        // Set tenant in manager if found
        if ($company) {
            // Check company status
            if ($company->status === 'suspended') {
                abort(403, 'تم تعليق حساب هذه المؤسسة. يرجى مراجعة الدعم الفني.');
            }

            $this->tenantManager->setTenant($company);

            // Set app locale according to company's language preference if not overridden by session
            if (!session()->has('locale')) {
                app()->setLocale($company->language ?? 'ar');
            }
        }

        return $next($request);
    }

    /**
     * Extract subdomain from host.
     */
    protected function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);
        
        // If e.g. anas.alostaz.io -> count is 3 -> 'anas'
        // If anas.localhost -> count is 2 -> 'anas'
        if (count($parts) >= 2) {
            return strtolower($parts[0]);
        }

        return null;
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant (company) for the request and guarantees that an
 * authenticated user can only ever operate inside their own company.
 *
 * Resolution order:
 *  1. Super admin impersonation (only honoured for real super admins).
 *  2. The request host: "{subdomain}.{central_domain}" or a mapped custom domain.
 *  3. Local/testing only: "X-Tenant" header or "?tenant=" query parameter.
 *  4. The authenticated user's own company (central domain deployments).
 *
 * Whatever the source, a logged-in user whose company differs from the
 * resolved one is logged out and rejected, so a session can never be replayed
 * against another company's subdomain.
 */
class ResolveTenant
{
    public function __construct(protected TenantManager $tenantManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Every web request runs with fail-closed tenant scoping.
        $this->tenantManager->forget();
        $this->tenantManager->enforce();

        $user = $request->user();
        $company = null;
        $fromHost = false;

        // A deactivated account loses access on its next request (session, remember-me cookie or API token).
        if ($user && $user->status !== null && $user->status !== 'active') {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort($request->expectsJson() ? 401 : 403, 'هذا الحساب موقوف. تواصل مع مدير المنشأة.');
        }

        // Impersonation is time limited.
        if ($this->tenantManager->isImpersonating() && (int) session('impersonate_expires_at', 0) < now()->timestamp) {
            $this->tenantManager->stopImpersonation();
            session()->forget('impersonate_expires_at');
        }

        // 1. Super admin impersonation.
        if ($user && $user->isSuperAdmin() && $this->tenantManager->isImpersonating()) {
            $company = Company::find(session('impersonate_company_id'));
        } elseif ($this->tenantManager->isImpersonating() && (! $user || ! $user->isSuperAdmin())) {
            // Stale or forged impersonation state on a non super admin session.
            session()->forget(['impersonate_company_id', 'impersonate_original_admin_id']);
        }

        // 2. Host based resolution.
        if (! $company) {
            $company = $this->resolveFromHost($request->getHost());
            $fromHost = $company !== null;

            if (! $company && $this->isTenantHost($request->getHost())) {
                abort(404, 'هذه المنشأة غير موجودة.');
            }

            // Links in emails (password reset, invitations) are built from the request host:
            // a forged Host header must never be served.
            if (! $company && ! $this->isKnownHost(strtolower($request->getHost()))) {
                abort(404);
            }
        }

        // 3. Developer override (local / testing only).
        if (! $company && $this->devOverrideAllowed()) {
            if ($devSubdomain = $request->header('X-Tenant') ?? $request->query('tenant')) {
                $company = Company::where('subdomain', strtolower((string) $devSubdomain))->first();
                $fromHost = $company !== null;
            }
        }

        // 4. Authenticated user's own company.
        if (! $company && $user && $user->company_id) {
            if (config('tenancy.enforce_subdomain') && ! $request->expectsJson()) {
                $own = $user->company;
                if ($own && $own->subdomain) {
                    return redirect()->away($this->tenantUrl($request, $own->subdomain));
                }
            }

            $company = $user->company;
        }

        if ($company) {
            // A user may only act inside their own company. Super admins may act
            // inside a company only while impersonating it.
            if ($user && ! $this->userMayAccess($user, $company)) {
                if ($request->hasSession()) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                abort(403, 'لا تملك صلاحية الوصول إلى هذه المنشأة.');
            }

            if ($company->status === 'suspended' && ! ($user && $user->isSuperAdmin())) {
                abort(403, 'تم تعليق حساب هذه المؤسسة. يرجى مراجعة الدعم الفني.');
            }

            $this->tenantManager->setTenant($company);

            if (! $request->hasSession() || ! session()->has('locale')) {
                app()->setLocale($company->language ?? 'ar');
            }
        }

        return $next($request);
    }

    protected function userMayAccess($user, Company $company): bool
    {
        if ($user->isSuperAdmin()) {
            return $this->tenantManager->isImpersonating()
                && (int) session('impersonate_company_id') === (int) $company->id;
        }

        return (int) $user->company_id === (int) $company->id;
    }

    /**
     * Resolve a company from "{subdomain}.{central}" or a custom domain.
     */
    protected function resolveFromHost(string $host): ?Company
    {
        $host = strtolower($host);

        if ($subdomain = $this->extractSubdomain($host)) {
            return Company::where('subdomain', $subdomain)->first();
        }

        if (! $this->isCentralHost($host) && ! $this->isIpAddress($host)) {
            return Company::where('custom_domain', $host)->whereNotNull('custom_domain_verified_at')->first();
        }

        return null;
    }

    /**
     * Whether the host is shaped like a tenant subdomain (so an unknown one is a 404).
     */
    protected function isTenantHost(string $host): bool
    {
        return $this->extractSubdomain(strtolower($host)) !== null;
    }

    /**
     * Extract the tenant label from "{label}.{central_domain}". Returns null for
     * the central domain itself, reserved labels and nested subdomains.
     */
    protected function extractSubdomain(string $host): ?string
    {
        $central = strtolower((string) config('tenancy.central_domain'));
        $suffix = '.'.$central;

        if ($central === '' || ! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        if (in_array($label, config('tenancy.reserved_subdomains', []), true)) {
            return null;
        }

        return $label;
    }

    protected function isCentralHost(string $host): bool
    {
        $central = strtolower((string) config('tenancy.central_domain'));

        if ($host === $central || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return true;
        }

        // Reserved subdomains of the central domain (www., admin., ...).
        foreach (config('tenancy.reserved_subdomains', []) as $reserved) {
            if ($host === $reserved.'.'.$central) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hosts served without a tenant: the central domain (and its reserved
     * subdomains), extra hosts allowed in config, and — unless unknown hosts
     * are rejected (production default) — anything else such as a LAN IP.
     */
    protected function isKnownHost(string $host): bool
    {
        if (! config('tenancy.reject_unknown_hosts')) {
            return true;
        }

        return $this->isCentralHost($host) || in_array($host, config('tenancy.allowed_hosts', []), true);
    }

    protected function isIpAddress(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    protected function devOverrideAllowed(): bool
    {
        return config('tenancy.allow_dev_override') && app()->environment('local', 'testing');
    }

    protected function tenantUrl(Request $request, string $subdomain): string
    {
        $port = in_array($request->getPort(), [80, 443], true) ? '' : ':'.$request->getPort();

        return $request->getScheme().'://'.$subdomain.'.'.config('tenancy.central_domain')
            .$port.$request->getRequestUri();
    }
}

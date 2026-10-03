<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Handle an incoming request and ensure user is Super Admin.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Impersonation state alone never grants access: only a real platform
        // super admin (who keeps their own identity while impersonating) passes.
        if (! $request->user() || ! $request->user()->isSuperAdmin()) {
            abort(403, 'غير مصرح لك بالوصول إلى لوحة التحكم الرئيسية.');
        }

        // With subdomain enforcement on, the console only answers on the
        // platform domains (admin.{central} or the bare central domain).
        if (config('tenancy.enforce_subdomain')) {
            $central = strtolower(config('tenancy.central_domain'));
            $host = strtolower($request->getHost());
            if (! in_array($host, [$central, 'admin.'.$central, 'www.'.$central], true)) {
                abort(404);
            }
        }

        return $next($request);
    }
}

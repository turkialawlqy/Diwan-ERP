<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    /**
     * Redirect insecure requests to HTTPS in production. Skipped for requests
     * already marked secure by a TLS-terminating proxy (X-Forwarded-Proto),
     * which Laravel's TrustProxies middleware exposes via isSecure().
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && !$request->isSecure()) {
            return redirect()->secure($request->getRequestUri());
        }

        return $next($request);
    }
}

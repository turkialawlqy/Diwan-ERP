<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Handle an incoming request and ensure user is Super Admin.
     * Also allows access when actively impersonating (to exit impersonation).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isSuper = $request->user() && $request->user()->isSuperAdmin();
        $isImpersonating = session()->has('impersonate_company_id');

        if (!$isSuper && !$isImpersonating) {
            abort(403, 'غير مصرح لك بالوصول إلى لوحة التحكم الرئيسية.');
        }

        return $next($request);
    }
}

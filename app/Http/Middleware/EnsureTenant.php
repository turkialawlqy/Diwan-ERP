<?php

namespace App\Http\Middleware;

use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every company (ERP) route: the request must have a resolved tenant.
 * A platform super admin who is not impersonating is sent to the platform panel.
 */
class EnsureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(TenantManager::class)->hasTenant()) {
            return $next($request);
        }

        if ($request->user()?->isSuperAdmin()) {
            return redirect()->route('super-admin.dashboard');
        }

        abort(403, 'لا توجد منشأة مرتبطة بهذا الحساب.');
    }
}

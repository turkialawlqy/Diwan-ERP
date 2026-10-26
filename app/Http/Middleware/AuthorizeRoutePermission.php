<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorizes every tenant route against the permission mapped to its name in
 * config('erp_permissions.routes'). Unmapped routes are denied, so a new route
 * can never be reachable by accident without an explicit permission.
 */
class AuthorizeRoutePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $name = $request->route()?->getName();
        $map = config('erp_permissions.routes', []);

        if (! $user || ! $name || ! array_key_exists($name, $map)) {
            abort(403, 'هذه الصفحة غير مرتبطة بأي صلاحية.');
        }

        $required = $map[$name];

        if ($required === '@authenticated') {
            return $next($request);
        }

        // API tokens carry their own abilities: a token may never do more
        // than the abilities it was issued with, nor more than its user.
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $tokenBound = $token instanceof PersonalAccessToken;

        foreach ((array) $required as $permission) {
            if ($user->can($permission) && (! $tokenBound || $token->can($permission))) {
                return $next($request);
            }
        }

        if ($request->expectsJson()) {
            abort(403, 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');
        }

        abort(403, 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');
    }
}

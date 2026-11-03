<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Only a signed-in, active portal user of this company gets in; idle sessions end after 30 minutes. */
class PortalAuth
{
    public const IDLE_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('portal');
        $user = $guard->user();
        $company = $request->attributes->get('portal_company');
        $last = (int) $request->session()->get('portal_last_activity_at', 0);

        $valid = $user && $user->isActive() && (int) $user->company_id === (int) $company?->id
            && (! $last || now()->timestamp - $last <= self::IDLE_MINUTES * 60);

        if (! $valid) {
            if ($user) {
                $guard->logout();
                $request->session()->forget('portal_last_activity_at');
                $request->session()->regenerateToken();
            }

            return $request->expectsJson() ? response()->json(['message' => 'انتهت الجلسة.'], 401) : redirect()->route('customer-portal.login', ['company' => $company?->id]);
        }

        $request->session()->put('portal_last_activity_at', now()->timestamp);

        return $next($request);
    }
}

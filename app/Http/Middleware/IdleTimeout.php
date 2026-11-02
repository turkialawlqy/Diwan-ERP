<?php

namespace App\Http\Middleware;

use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a user out after the company's idle time («المهلة قبل تسجيل الخروج»,
 * setting session_timeout_minutes; 0 = off). Background polling does not count as
 * activity; the page's warning dialog extends the session through session.keep-alive.
 */
class IdleTimeout
{
    /** Requests the page makes by itself (not the user). */
    protected const PASSIVE = ['system.update.status', 'session.idle-status'];

    public const DEFAULT_MINUTES = 30;

    public static function minutes(): int
    {
        $company = app(TenantManager::class)->getCompany();

        return max(0, (int) ($company?->setting('session_timeout_minutes', self::DEFAULT_MINUTES) ?? self::DEFAULT_MINUTES));
    }

    /** Cashier screens stay open all day: POS pages never sign out. */
    public static function exempt(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'pos.');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }

        $minutes = self::exempt($request) ? 0 : self::minutes();
        $last = (int) $request->session()->get('last_activity_at', 0);

        if ($minutes > 0 && $last > 0 && time() - $last > $minutes * 60) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = "تم تسجيل خروجك تلقائيًا بعد {$minutes} دقيقة دون نشاط.";

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => $message], 401)
                : redirect()->guest(route('login'))->with('status', $message);
        }

        if (! in_array($request->route()?->getName(), self::PASSIVE, true)) {
            $request->session()->put('last_activity_at', time());
        }

        return $next($request);
    }
}

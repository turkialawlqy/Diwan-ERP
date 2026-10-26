<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users who must use two-factor authentication (platform admins, and roles
 * the company made it mandatory for) are sent to enrol before anything else.
 */
class EnsureTwoFactorEnrolled
{
    public function __construct(protected TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasTwoFactorEnabled() && $this->twoFactor->required($user)) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, 'يجب تفعيل التحقق الثنائي أولاً.');
            }

            return redirect()->route('profile.edit', ['two_factor' => 'required'])
                ->with('error', 'تفعيل التحقق الثنائي إلزامي لحسابك. فعّله للمتابعة.');
        }

        return $next($request);
    }
}

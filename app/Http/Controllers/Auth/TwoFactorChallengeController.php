<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Second login step. The password step only leaves a short-lived pending
 * login in the session; the user is authenticated after a valid code.
 */
class TwoFactorChallengeController extends Controller
{
    protected const TTL_MINUTES = 10;

    public static function begin(Request $request, User $user, bool $remember): void
    {
        $request->session()->put('login.two_factor', ['id' => $user->id, 'remember' => $remember, 'expires' => now()->addMinutes(self::TTL_MINUTES)->timestamp]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->with('status', 'انتهت مهلة التحقق، سجّل الدخول مرة أخرى.');
        }

        $request->validate(['code' => 'nullable|string|max:10', 'recovery_code' => 'nullable|string|max:20'], [], ['code' => 'رمز التحقق', 'recovery_code' => 'رمز الاسترداد']);

        $key = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'محاولات كثيرة، حاول بعد '.RateLimiter::availableIn($key).' ثانية.']);
        }

        if (! $twoFactor->verifyLogin($user, $request->input('code'), $request->input('recovery_code'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([$request->filled('recovery_code') ? 'recovery_code' : 'code' => 'الرمز غير صحيح.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('login.two_factor')['remember'];
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    protected function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('login.two_factor');
        if (! $pending || $pending['expires'] < now()->timestamp) {
            $request->session()->forget('login.two_factor');

            return null;
        }

        $user = User::find($pending['id']);

        return $user?->hasTwoFactorEnabled() ? $user : null;
    }
}

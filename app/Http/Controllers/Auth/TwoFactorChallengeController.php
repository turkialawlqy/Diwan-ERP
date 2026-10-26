<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

        // Failed codes lock the account out; each further lockout the same day doubles
        // its length (5 tries / 15 min, then 30, 60...), so guessing the 6 digits is impractical.
        $key = 'two-factor:'.$user->id;
        $lockouts = 'two-factor-lockouts:'.$user->id.':'.now()->toDateString();
        $max = max(1, (int) config('security.two_factor_max_attempts', 5));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['code' => 'محاولات كثيرة، حاول بعد '.(int) ceil(RateLimiter::availableIn($key) / 60).' دقيقة.']);
        }

        if (! $twoFactor->verifyLogin($user, $request->input('code'), $request->input('recovery_code'))) {
            $count = (int) Cache::get($lockouts, 0);
            RateLimiter::hit($key, (int) config('security.two_factor_lockout_minutes', 15) * 60 * (2 ** min($count, 6)));
            if (RateLimiter::attempts($key) >= $max) {
                Cache::put($lockouts, $count + 1, now()->endOfDay());
                AuditLog::record('two_factor_lockout', $user, [], ['ip' => $request->ip(), 'lockouts_today' => $count + 1], $user->company_id, $user->id);
            }
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

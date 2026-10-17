<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TenantManager;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google OAuth.
     */
    public function redirectToGoogle(): RedirectResponse
    {
        try {
            return Socialite::driver('google')->redirect();
        } catch (Exception $e) {
            Log::error('Google OAuth Redirect Error: '.$e->getMessage());

            return redirect()->route('login')->with('error', 'تعذر الاتصال بخدمة Google. يرجى التأكد من إعدادات المفاتيح.');
        }
    }

    /**
     * Handle Google OAuth Callback.
     */
    public function handleGoogleCallback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            // Find existing user by google_id or email
            $tenantId = app(TenantManager::class)->getTenantId();

            // Match by Google id, or by a *verified* Google email; on a company
            // subdomain only that company's active users are eligible.
            $user = User::query()
                ->where(function ($q) use ($googleUser) {
                    $q->where('google_id', $googleUser->getId());

                    // Linked by email only to an account whose address was itself verified:
                    // otherwise anyone who registered the address first would receive the owner's Google login.
                    if (($googleUser->user['email_verified'] ?? $googleUser->user['verified_email'] ?? false) && $googleUser->getEmail()) {
                        $q->orWhere(fn ($q) => $q->where('email', strtolower($googleUser->getEmail()))->whereNotNull('email_verified_at'));
                    }
                })
                ->when($tenantId, fn ($q) => $q->where('company_id', $tenantId))
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
                ->first();

            if ($user) {
                // Update google_id and avatar if missing
                if (! $user->google_id) {
                    $user->update([
                        'google_id' => $googleUser->getId(),
                        'avatar' => $googleUser->getAvatar(),
                    ]);
                }

                // Google proves the password step only; two-factor still applies.
                if ($user->hasTwoFactorEnabled()) {
                    TwoFactorChallengeController::begin(request(), $user, false);

                    return redirect()->route('two-factor.challenge');
                }

                Auth::login($user);
                request()->session()->regenerate();

                return redirect()->intended(route('dashboard'));
            }

            // If user does not exist, redirect to registration with message
            return redirect()->route('register')->with([
                'info' => 'يرجى إكمال تسجيل منشأتك لربط حساب Google الجديد.',
                'email' => $googleUser->getEmail(),
                'name' => $googleUser->getName(),
            ]);

        } catch (Exception $e) {
            Log::error('Google OAuth Callback Error: '.$e->getMessage());

            return redirect()->route('login')->with('error', 'حدث خطأ أثناء تسجيل الدخول بواسطة Google.');
        }
    }
}

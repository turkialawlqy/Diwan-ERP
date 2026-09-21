<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
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
            Log::error('Google OAuth Redirect Error: ' . $e->getMessage());
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
            $user = User::where('google_id', $googleUser->getId())
                ->orWhere('email', $googleUser->getEmail())
                ->first();

            if ($user) {
                // Update google_id and avatar if missing
                if (!$user->google_id) {
                    $user->update([
                        'google_id' => $googleUser->getId(),
                        'avatar' => $googleUser->getAvatar(),
                    ]);
                }

                Auth::login($user);
                return redirect()->intended(route('dashboard'));
            }

            // If user does not exist, redirect to registration with message
            return redirect()->route('register')->with([
                'info' => 'يرجى إكمال تسجيل منشأتك لربط حساب Google الجديد.',
                'email' => $googleUser->getEmail(),
                'name' => $googleUser->getName(),
            ]);

        } catch (Exception $e) {
            Log::error('Google OAuth Callback Error: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'حدث خطأ أثناء تسجيل الدخول بواسطة Google.');
        }
    }
}

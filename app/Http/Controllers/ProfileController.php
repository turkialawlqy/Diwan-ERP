<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Auth\TwoFactor;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'twoFactor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'required' => app(TwoFactor::class)->required($user),
                'recoveryRemaining' => $user->hasTwoFactorEnabled() ? count((array) $user->two_factor_recovery_codes) : 0,
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill(collect($request->validated())->only(['name', 'email'])->all());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Company accounts are closed by an owner from Users (kept when they have activity);
        // the platform admin and the last company owner can never remove themselves.
        if ($user->isSuperAdmin() || $user->company_id) {
            return back()->withErrors(['password' => 'لا يمكن حذف هذا الحساب من الملف الشخصي. اطلب من مدير المنشأة إيقافه من شاشة المستخدمين.']);
        }

        Auth::logout();

        $user->revokeAccess();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}

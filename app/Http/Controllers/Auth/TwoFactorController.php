<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Managing one's own two-factor authentication from the profile page.
 */
class TwoFactorController extends Controller
{
    public function __construct(protected TwoFactor $twoFactor) {}

    public function enable(Request $request): JsonResponse
    {
        $request->validate(['current_password' => 'required|current_password'], [], ['current_password' => 'كلمة المرور الحالية']);
        // Starting over would silently switch protection off: an active setup is disabled explicitly first.
        if ($request->user()->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['current_password' => 'التحقق الثنائي مفعّل. عطّله أولاً ثم أعد ربط التطبيق.']);
        }

        return response()->json($this->twoFactor->begin($request->user()));
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:10'], [], ['code' => 'رمز التحقق']);
        $codes = $this->twoFactor->confirm($request->user(), $request->input('code'));
        if (! $codes) {
            throw ValidationException::withMessages(['code' => 'الرمز غير صحيح، تأكد من وقت جهازك وأعد المحاولة.']);
        }

        return response()->json(['recovery_codes' => $codes]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['current_password' => 'required|current_password'], [], ['current_password' => 'كلمة المرور الحالية']);
        abort_unless($request->user()->hasTwoFactorEnabled(), 422);

        return response()->json(['recovery_codes' => $this->twoFactor->freshRecoveryCodes($request->user())]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['current_password' => 'required|current_password'], [], ['current_password' => 'كلمة المرور الحالية']);
        if ($this->twoFactor->required($request->user())) {
            throw ValidationException::withMessages(['current_password' => 'التحقق الثنائي إلزامي لدورك في المنشأة ولا يمكن تعطيله.']);
        }
        $this->twoFactor->disable($request->user());

        return response()->json(['ok' => true]);
    }
}

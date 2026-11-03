<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Sign in to the customer portal with an e-mailed one-time code. */
class AuthController extends Controller
{
    public function __construct(protected PortalLoginService $login) {}

    public function showLogin(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('portal')->check()) {
            return redirect()->route('customer-portal.home', ['company' => $this->company($request)->id]);
        }

        return Inertia::render('Portal/Login', ['company' => $this->brand($request)]);
    }

    public function sendCode(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $company = $this->company($request);

        if (! $this->login->send($company->id, $data['email'], $request->ip())) {
            return back()->withErrors(['email' => 'طلبات كثيرة. حاول بعد قليل.']);
        }

        // The same answer for any address: nothing tells whether it is registered.
        $request->session()->put('portal_login_email', mb_strtolower(trim($data['email'])));

        return redirect()->route('customer-portal.verify', ['company' => $company->id]);
    }

    public function showVerify(Request $request): Response|RedirectResponse
    {
        $company = $this->company($request);
        $email = $request->session()->get('portal_login_email');
        if (! $email) {
            return redirect()->route('customer-portal.login', ['company' => $company->id]);
        }

        return Inertia::render('Portal/Verify', ['company' => $this->brand($request), 'email' => $email]);
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:12']);
        $company = $this->company($request);
        $email = (string) $request->session()->get('portal_login_email');

        $user = $email !== '' ? $this->login->verify($company->id, $email, $data['code']) : null;
        if (! $user) {
            return back()->withErrors(['code' => 'الرمز غير صحيح أو منتهٍ. اطلب رمزًا جديدًا إن لزم.']);
        }

        Auth::guard('portal')->login($user);
        $request->session()->regenerate();
        $request->session()->forget('portal_login_email');
        $request->session()->put('portal_last_activity_at', time());

        return redirect()->route('customer-portal.home', ['company' => $company->id]);
    }

    public function logout(Request $request)
    {
        $company = $this->company($request);
        Auth::guard('portal')->logout();
        $request->session()->forget('portal_last_activity_at');
        $request->session()->regenerateToken();

        return redirect()->route('customer-portal.login', ['company' => $company->id]);
    }

    protected function company(Request $request)
    {
        return $request->attributes->get('portal_company');
    }

    protected function brand(Request $request): array
    {
        $company = $this->company($request);

        return ['id' => $company->id, 'name' => $company->name];
    }
}

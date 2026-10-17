<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Saas\SubscriptionService;
use App\Services\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    protected TenantManager $tenantManager;

    public function __construct(TenantManager $tenantManager)
    {
        $this->tenantManager = $tenantManager;
    }

    /**
     * Start impersonating a company to diagnose issues, edit, or provide training.
     */
    public function enter(Request $request, Company $company): RedirectResponse
    {
        if (! $request->user() || ! $request->user()->isSuperAdmin()) {
            abort(403, 'غير مصرح لك بالدخول إلى منشآت العملاء.');
        }

        $validated = $request->validate(['reason' => 'required|string|min:5|max:500']);

        // Entering a customer's data needs a recently confirmed password (a hijacked admin session alone is not enough).
        if (now()->timestamp - (int) $request->session()->get('auth.password_confirmed_at', 0) > (int) config('auth.password_timeout', 10800)) {
            $request->session()->put('url.intended', url()->previous());

            return redirect()->route('password.confirm')->with('status', 'أكّد كلمة المرور ثم أعد طلب الدخول إلى المنشأة.');
        }

        $this->tenantManager->startImpersonation($company, $request->user());
        session(['impersonate_expires_at' => now()->addMinutes((int) config('tenancy.impersonation_minutes', 60))->timestamp]);
        app(SubscriptionService::class)->audit('impersonation_started', $company, ['reason' => $validated['reason']]);

        return redirect()->route('dashboard')->with('success', "تم الدخول بنجاح على نظام منشأة: {$company->name} في وضع المساندة والتدريب.");
    }

    /**
     * Exit impersonation mode and return to Super Admin dashboard.
     */
    public function leave(): RedirectResponse
    {
        $companyId = session('impersonate_company_id');
        $this->tenantManager->stopImpersonation();
        session()->forget('impersonate_expires_at');
        app(SubscriptionService::class)->audit('impersonation_ended', $companyId ? Company::find($companyId) : null);

        return redirect()->route('super-admin.dashboard')->with('info', 'تم إنهاء وضع المساندة والعودة للوحة تحكم النظام العام.');
    }
}

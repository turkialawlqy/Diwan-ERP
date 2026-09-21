<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
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
        if (!$request->user() || !$request->user()->isSuperAdmin()) {
            abort(403, 'غير مصرح لك بالدخول إلى منشآت العملاء.');
        }

        $this->tenantManager->startImpersonation($company, $request->user());

        return redirect()->route('dashboard')->with('success', "تم الدخول بنجاح على نظام منشأة: {$company->name} في وضع المساندة والتدريب.");
    }

    /**
     * Exit impersonation mode and return to Super Admin dashboard.
     */
    public function leave(): RedirectResponse
    {
        $this->tenantManager->stopImpersonation();

        return redirect()->route('super-admin.dashboard')->with('info', 'تم إنهاء وضع المساندة والعودة للوحة تحكم النظام العام.');
    }
}

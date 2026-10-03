<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Saas\PlanGuard;
use App\Services\Saas\SubscriptionService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Platform owner console: companies, plans, subscription payments, audit.
 */
class PlatformController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions) {}

    public function company(Company $company, PlanGuard $guard)
    {
        $company->load('plan');

        return Inertia::render('SuperAdmin/Company', [
            'company' => $company->makeVisible('custom_domain_token'),
            'state' => app(TenantManager::class)->runAs($company, fn () => $guard->state($company)),
            'usage' => app(TenantManager::class)->runAs($company, fn () => $guard->usage($company)),
            'users' => User::where('company_id', $company->id)->orderBy('name')->get(['id', 'name', 'email', 'status', 'last_login_at']),
            'invoices' => SubscriptionInvoice::where('company_id', $company->id)->with('plan:id,name_ar')->latest('id')->get(),
            'audit' => PlatformAuditLog::with('user:id,name')->where('company_id', $company->id)->latest('id')->limit(50)->get(),
            'plans' => SubscriptionPlan::orderBy('sort')->get(['id', 'name_ar', 'price_monthly', 'price_yearly']),
        ]);
    }

    public function updateCompany(Request $request, Company $company)
    {
        $validated = $request->validate([
            'subscription_plan_id' => 'nullable|exists:subscription_plans,id',
            'status' => 'required|in:trial,active,suspended',
            'trial_ends_at' => 'nullable|date',
            'subscription_ends_at' => 'nullable|date',
            'suspended_reason' => 'nullable|required_if:status,suspended|string|max:500',
        ]);

        $before = $company->only(array_keys($validated));
        $company->forceFill($validated)->save();
        $this->subscriptions->audit('company_updated', $company, ['before' => $before, 'after' => $validated]);

        return back()->with('success', 'تم تحديث المنشأة');
    }

    public function verifyDomain(Company $company)
    {
        $company->forceFill(['custom_domain_verified_at' => $company->custom_domain ? now() : null])->save();
        $this->subscriptions->audit('domain_verified_manually', $company, ['domain' => $company->custom_domain]);

        return back()->with('success', 'تم اعتماد النطاق يدوياً');
    }

    public function plans()
    {
        return Inertia::render('SuperAdmin/Plans', [
            'plans' => SubscriptionPlan::withCount('companies')->orderBy('sort')->get(),
            'modules' => config('saas.modules'),
        ]);
    }

    public function savePlan(Request $request, ?SubscriptionPlan $plan = null)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:100',
            'name_en' => 'required|string|max:100',
            'slug' => ['required', 'alpha_dash', 'max:50', Rule::unique('subscription_plans')->ignore($plan?->id)],
            'description_ar' => 'nullable|string|max:1000',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'required|numeric|min:0',
            'max_users' => 'required|integer|min:-1',
            'max_branches' => 'required|integer|min:-1',
            'max_warehouses' => 'required|integer|min:-1',
            'max_employees' => 'required|integer|min:-1',
            'max_invoices_per_month' => 'required|integer|min:-1',
            'trial_days' => 'required|integer|min:0|max:90',
            'modules' => 'nullable|array',
            'modules.*' => ['string', Rule::in(array_keys(config('saas.modules')))],
            'is_active' => 'boolean',
            'is_popular' => 'boolean',
            'sort' => 'nullable|integer',
        ]);
        $validated['name_en'] ??= $validated['name_ar'];

        $plan ? $plan->update($validated) : SubscriptionPlan::create($validated);
        $this->subscriptions->audit('plan_saved', null, ['slug' => $validated['slug']]);

        return back()->with('success', 'تم حفظ الباقة');
    }

    public function invoices(Request $request)
    {
        return Inertia::render('SuperAdmin/Invoices', [
            'invoices' => SubscriptionInvoice::with(['company:id,name,subdomain', 'plan:id,name_ar'])
                ->when($request->input('status', 'unpaid') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'unpaid')))
                ->latest('id')->paginate(30)->withQueryString(),
            'status' => $request->input('status', 'unpaid'),
        ]);
    }

    public function confirmInvoice(Request $request, SubscriptionInvoice $invoice)
    {
        $validated = $request->validate(['payment_reference' => 'nullable|string|max:100', 'payment_method' => 'nullable|string|max:30']);
        $this->subscriptions->confirmPayment($invoice, $validated['payment_reference'] ?? null, $validated['payment_method'] ?? 'bank_transfer');

        return back()->with('success', 'تم تأكيد السداد وتفعيل الاشتراك');
    }

    public function voidInvoice(SubscriptionInvoice $invoice)
    {
        abort_unless($invoice->status === 'unpaid', 422);
        $invoice->update(['status' => 'void']);
        $this->subscriptions->audit('subscription_invoice_voided', $invoice->company, ['invoice' => $invoice->number]);

        return back()->with('success', 'تم إلغاء الفاتورة');
    }

    public function audit(Request $request)
    {
        return Inertia::render('SuperAdmin/Audit', [
            'logs' => PlatformAuditLog::with(['user:id,name', 'company:id,name'])->latest('id')->paginate(50),
        ]);
    }
}

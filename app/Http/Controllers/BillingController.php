<?php

namespace App\Http\Controllers;

use App\Jobs\ExportCompanyData;
use App\Models\DataExport;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Services\Saas\DomainVerifier;
use App\Services\Saas\PlanGuard;
use App\Services\Saas\SubscriptionService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Mpdf\QrCode\Output\Png;
use Mpdf\QrCode\QrCode;

/**
 * Company subscription: plan, usage, invoices, plan changes, custom domain
 * and full data export (owner only).
 */
class BillingController extends Controller
{
    public function __construct(protected TenantManager $tenantManager) {}

    public function index(PlanGuard $guard)
    {
        $company = $this->tenantManager->getCompany()->load('plan');

        return Inertia::render('Settings/Billing', [
            'company' => $company->only(['id', 'name', 'status', 'trial_ends_at', 'subscription_ends_at', 'billing_cycle', 'custom_domain', 'custom_domain_verified_at', 'subdomain']) + [
                'custom_domain_token' => $company->custom_domain_token,
            ],
            'plan' => $company->plan,
            'state' => $guard->state($company),
            'usage' => $guard->usage($company),
            'plans' => SubscriptionPlan::where('is_active', true)->orderBy('sort')->orderBy('price_monthly')->get(),
            'modules' => config('saas.modules'),
            'invoices' => SubscriptionInvoice::where('company_id', $company->id)->with('plan:id,name_ar')->latest('id')->limit(24)->get(),
            'exports' => DataExport::latest('id')->limit(5)->get(),
            'centralDomain' => config('tenancy.central_domain'),
            // Where to pay (bank transfer), shown with the unpaid invoice.
            'payment' => collect(config('saas.platform'))->only(['name', 'bank_name', 'account_name', 'iban', 'email'])->all(),
        ]);
    }

    /** Printable tax invoice of the platform subscription (own company only). */
    public function invoice(SubscriptionInvoice $invoice)
    {
        abort_unless((int) $invoice->company_id === (int) $this->tenantManager->getTenantId(), 404);

        return self::renderInvoice($invoice);
    }

    public static function renderInvoice(SubscriptionInvoice $invoice)
    {
        $invoice->loadMissing('company', 'plan');
        $qr = $invoice->qr
            ? 'data:image/png;base64,'.base64_encode((new Png)->output(new QrCode($invoice->qr, QrCode::ERROR_CORRECTION_MEDIUM), 240))
            : null;

        return response()->view('billing.invoice', ['invoice' => $invoice, 'platform' => config('saas.platform'), 'qr' => $qr]);
    }

    public function requestPlan(Request $request, SubscriptionService $subscriptions)
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);
        $invoice = $subscriptions->request($this->tenantManager->getCompany(), SubscriptionPlan::findOrFail($validated['plan_id']), $validated['billing_cycle']);
        if ($invoice->status === 'paid') {
            $subscriptions->activate($invoice);
        }

        return back()->with('success', $invoice->status === 'paid'
            ? 'تم تفعيل الباقة'
            : "تم إصدار فاتورة الاشتراك {$invoice->number}. يُفعّل الاشتراك عند تأكيد السداد.");
    }

    public function updateDomain(Request $request, DomainVerifier $verifier)
    {
        $validated = $request->validate(['custom_domain' => 'nullable|string|max:253']);
        $verifier->assign($this->tenantManager->getCompany(), $validated['custom_domain'] ?? null);

        return back()->with('success', 'تم حفظ النطاق. أضف سجل TXT ثم اضغط تحقق.');
    }

    public function verifyDomain(DomainVerifier $verifier)
    {
        return $verifier->verify($this->tenantManager->getCompany())
            ? back()->with('success', 'تم التحقق من ملكية النطاق وتفعيله.')
            : back()->with('error', 'لم يُعثر على سجل TXT المطلوب بعد. قد يستغرق انتشار DNS حتى 48 ساعة.');
    }

    public function exportData(Request $request)
    {
        $export = DataExport::create(['user_id' => $request->user()->id, 'status' => 'queued']);
        ExportCompanyData::dispatch($export->id, $this->tenantManager->getTenantId());

        return back()->with('success', 'بدأ تجهيز نسخة بيانات المنشأة؛ ستظهر هنا عند اكتمالها.');
    }

    public function downloadExport(DataExport $export)
    {
        abort_unless($export->status === 'ready' && $export->path && Storage::disk('local')->exists($export->path), 404);

        return Storage::disk('local')->download($export->path, 'diwan-data-'.$export->created_at->format('Ymd-His').'.zip');
    }
}

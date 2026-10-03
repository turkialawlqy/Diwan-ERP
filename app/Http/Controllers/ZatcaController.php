<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitZatcaDocument;
use App\Models\Branch;
use App\Models\ZatcaDevice;
use App\Models\ZatcaDocument;
use App\Services\TenantManager;
use App\Services\Zatca\DocumentMapper;
use App\Services\Zatca\EInvoiceManager;
use App\Services\Zatca\InvoiceSigner;
use App\Services\Zatca\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * E-invoicing compliance: seller profile, EGS devices onboarding and the
 * clearance / reporting monitor.
 */
class ZatcaController extends Controller
{
    public const SELLER_FIELDS = ['seller_street', 'seller_building_number', 'seller_additional_number', 'seller_district', 'seller_city', 'seller_postal_code'];

    public function __construct(protected TenantManager $tenantManager) {}

    public function index(Request $request, DocumentMapper $mapper)
    {
        $company = $this->tenantManager->getCompany();
        $filters = $request->validate(['status' => 'nullable|in:pending,submitting,cleared,reported,warning,rejected,failed', 'search' => 'nullable|string|max:100']);

        $documents = ZatcaDocument::with('device:id,name')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('document_number', 'like', "%{$s}%"))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Zatca/Index', [
            'devices' => ZatcaDevice::with('branch:id,name_ar')->orderBy('id')->get(),
            'documents' => $documents,
            'stats' => ZatcaDocument::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'overdue' => ZatcaDocument::where('submission', 'reporting')->whereIn('status', ['pending', 'failed'])->where('created_at', '<', EInvoiceManager::reportingDeadline())->count(),
            'seller' => collect(self::SELLER_FIELDS)->mapWithKeys(fn ($k) => [$k => $company->setting($k)])
                ->merge(['name' => $company->name, 'tax_number' => $company->tax_number, 'commercial_registration' => $company->commercial_registration]),
            'missing' => $mapper->missingSellerFields($company),
            'branches' => Branch::where('is_active', true)->get(['id', 'name_ar']),
            'phase' => ZatcaDevice::where('status', 'active')->exists() ? 'phase2' : 'phase1',
            'taxDefaults' => [
                'zero_category' => $company->setting('zatca_default_zero_category', 'Z'),
                'Z' => $company->setting('zatca_default_exemption_Z'),
                'E' => $company->setting('zatca_default_exemption_E'),
                'O' => $company->setting('zatca_default_exemption_O'),
                'out_of_scope_reason' => $company->setting('zatca_out_of_scope_reason'),
            ],
            'vatCategories' => config('zatca.vat_categories'),
            'exemptionReasons' => config('zatca.exemption_reasons'),
            'filters' => $filters,
        ]);
    }

    public function updateSeller(Request $request)
    {
        $validated = $request->validate([
            'tax_number' => ['required', 'regex:/^3\d{13}3$/'],
            'commercial_registration' => 'required|string|max:20',
            'seller_street' => 'required|string|max:120',
            'seller_building_number' => 'required|digits:4',
            'seller_additional_number' => 'nullable|digits:4',
            'seller_district' => 'required|string|max:120',
            'seller_city' => 'required|string|max:120',
            'seller_postal_code' => 'required|digits:5',
        ], ['tax_number.regex' => 'الرقم الضريبي 15 رقماً يبدأ وينتهي بالرقم 3.']);

        $company = $this->tenantManager->getCompany();
        $company->update(['tax_number' => $validated['tax_number'], 'commercial_registration' => $validated['commercial_registration']]);
        foreach (self::SELLER_FIELDS as $key) {
            $company->setSetting($key, $validated[$key] ?? '', 'zatca');
        }

        return back()->with('success', 'تم حفظ بيانات البائع');
    }

    /**
     * Company defaults for lines at 0% VAT without a product-level reason.
     */
    public function updateTaxDefaults(Request $request)
    {
        $reasons = config('zatca.exemption_reasons');
        $validated = $request->validate([
            'zero_category' => 'required|in:Z,E,O',
            'Z' => ['nullable', Rule::in(array_keys($reasons['Z']))],
            'E' => ['nullable', Rule::in(array_keys($reasons['E']))],
            'O' => ['nullable', Rule::in(array_keys($reasons['O']))],
            'out_of_scope_reason' => 'nullable|string|max:250',
        ]);

        $company = $this->tenantManager->getCompany();
        $company->setSetting('zatca_default_zero_category', $validated['zero_category'], 'zatca');
        foreach (['Z', 'E', 'O'] as $category) {
            $company->setSetting('zatca_default_exemption_'.$category, $validated[$category] ?? '', 'zatca');
        }
        $company->setSetting('zatca_out_of_scope_reason', $validated['out_of_scope_reason'] ?? '', 'zatca');

        return back()->with('success', 'تم حفظ إعدادات الإعفاء الضريبي');
    }

    public function storeDevice(Request $request, OnboardingService $onboarding)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:60|regex:/^[A-Za-z0-9\-_ ]+$/',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'environment' => 'required|in:sandbox,simulation,production',
            'invoice_types' => 'required|in:1000,0100,1100',
            'business_category' => 'required|string|max:100',
        ], ['name.regex' => 'اسم الجهاز بالأحرف الإنجليزية والأرقام فقط.']);

        $onboarding->createDevice($this->tenantManager->getCompany(), $validated);

        return back()->with('success', 'تم إنشاء الجهاز ومفتاح التشفير وطلب الشهادة (CSR). أدخل رمز OTP من بوابة فاتورة لإكمال الربط.');
    }

    /**
     * OTP → compliance CSID → compliance checks → production CSID.
     */
    public function onboard(Request $request, ZatcaDevice $device, OnboardingService $onboarding)
    {
        $validated = $request->validate(['otp' => 'nullable|digits_between:6,8']);
        $company = $this->tenantManager->getCompany();

        if ($device->status === 'new') {
            if (empty($validated['otp'])) {
                throw ValidationException::withMessages(['otp' => 'أدخل رمز OTP من بوابة فاتورة.']);
            }
            $onboarding->requestComplianceCsid($device, $validated['otp']);
        }

        if ($device->fresh()->status === 'compliance') {
            $results = $onboarding->runComplianceChecks($device->fresh(), $company);
            $failed = array_keys(array_filter($results, fn ($r) => $r['status'] !== 'passed'));
            if ($failed) {
                return back()->with('error', 'لم تجتز بعض نماذج الامتثال: '.implode('، ', $failed).'. راجع رسائل الأخطاء في بطاقة الجهاز.');
            }
            $onboarding->requestProductionCsid($device->fresh());
        }

        return back()->with('success', 'تم ربط الجهاز بمنصة فاتورة واعتماده. الفواتير الجديدة تُوقّع وتُرسل آلياً.');
    }

    public function renew(Request $request, ZatcaDevice $device, OnboardingService $onboarding)
    {
        $validated = $request->validate(['otp' => 'required|digits_between:6,8']);
        abort_unless($device->status === 'active', 422, 'الجهاز غير مفعل.');

        $onboarding->renew($device, $this->tenantManager->getCompany(), $validated['otp']);

        return back()->with('success', 'تم تجديد شهادة الجهاز');
    }

    public function revoke(ZatcaDevice $device)
    {
        $device->forceFill(['status' => 'revoked'])->save();

        return back()->with('success', 'تم إيقاف الجهاز. الفواتير الجديدة تعود للمرحلة الأولى ما لم يوجد جهاز آخر مفعل.');
    }

    public function resubmit(ZatcaDocument $document)
    {
        if (! in_array($document->status, ['failed', 'pending'], true)) {
            return back()->with('error', 'يمكن إعادة إرسال المستندات المعلقة أو المتعثرة فقط. المستند المرفوض يُصحّح بإشعار دائن/مدين.');
        }

        SubmitZatcaDocument::dispatch($document->id, $document->company_id);

        return back()->with('success', 'تمت جدولة إعادة الإرسال');
    }

    public function xml(ZatcaDocument $document, InvoiceSigner $signer)
    {
        return response($document->finalXml(), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9\-_]/', '_', $document->document_number).'.xml"',
            'X-Signature-Valid' => $signer->verify($document->signed_xml) ? '1' : '0',
        ]);
    }
}

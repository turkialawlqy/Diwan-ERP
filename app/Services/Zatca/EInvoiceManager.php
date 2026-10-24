<?php

namespace App\Services\Zatca;

use App\Jobs\SubmitZatcaDocument;
use App\Models\Company;
use App\Models\ZatcaDevice;
use App\Models\ZatcaDocument;
use App\Services\EInvoicing\ProviderRegistry;
use App\Services\EInvoicing\ZatcaProvider;
use App\Services\Saas\WebhookDispatcher;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entry point for e-invoicing of every sales document.
 *
 *  - Phase 1 (no onboarded device): QR code with tags 1-5.
 *  - Phase 2 (active device): UBL XML signed with the EGS key, chained to the
 *    previous document (ICV + PIH), stored, then cleared (standard / B2B) or
 *    reported (simplified / B2C) by a queued job.
 */
class EInvoiceManager
{
    public function __construct(
        protected TenantManager $tenantManager,
        protected DocumentMapper $mapper,
        protected UblInvoiceBuilder $builder,
        protected InvoiceSigner $signer,
    ) {}

    /**
     * Stamps a sales document with the e-invoicing system of its branch's
     * country: ZATCA for Saudi branches, the country's own system elsewhere.
     */
    public function stamp(Model $document): ?ZatcaDocument
    {
        $provider = app(ProviderRegistry::class)->for(TaxProfile::forDocument($document));
        if (! $provider instanceof ZatcaProvider) {
            $provider->stamp($document);

            return null;
        }

        return $this->stampZatca($document);
    }

    public function stampZatca(Model $document): ?ZatcaDocument
    {
        $company = $this->tenantManager->getCompany() ?? Company::find($document->company_id);
        $device = $company ? $this->device($company, $document->branch_id ?? null) : null;

        if (! $device) {
            $this->phaseOne($document, $company);

            return null;
        }

        return DB::transaction(fn () => $this->phaseTwo($document, $company, $device));
    }

    /**
     * The active device for the document's branch, else the company's default one.
     */
    public function device(Company $company, ?int $branchId = null): ?ZatcaDevice
    {
        $devices = ZatcaDevice::where('company_id', $company->id)->where('status', 'active')->get(['id', 'branch_id']);
        if ($devices->isEmpty()) {
            return null;
        }

        $id = $devices->firstWhere('branch_id', $branchId)?->id ?? $devices->firstWhere('branch_id', null)?->id ?? $devices->first()->id;

        return ZatcaDevice::find($id);
    }

    protected function phaseOne(Model $document, ?Company $company): void
    {
        $data = $this->mapper->map($document, $company);
        $totals = $this->builder->totals($data['lines']);

        $document->forceFill([
            'zatca_qr' => Tlv::encode([
                1 => $company?->name ?? '',
                2 => $company?->tax_number ?? '',
                3 => $data['issue_date'].'T'.$data['issue_time'],
                4 => number_format($totals['net'] + $totals['tax'], 2, '.', ''),
                5 => number_format($totals['tax'], 2, '.', ''),
            ]),
            'zatca_status' => 'generated',
        ])->saveQuietly();
    }

    protected function phaseTwo(Model $document, Company $company, ZatcaDevice $device): ZatcaDocument
    {
        if ($missing = $this->mapper->missingSellerFields($company)) {
            throw ValidationException::withMessages(['zatca' => 'بيانات البائع ناقصة للفوترة الإلكترونية: '.implode('، ', $missing)]);
        }

        // The device row lock serialises the hash chain: one ICV, one PIH.
        $device = ZatcaDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
        $icv = $device->icv + 1;
        $previousHash = $device->last_hash ?: ZatcaDevice::INITIAL_HASH;

        $data = $this->mapper->map($document, $company);
        // ZATCA rejects a document dated after today (BR-KSA-04): stop before it takes an ICV.
        if ($data['issue_date'] > now('Asia/Riyadh')->toDateString()) {
            throw ValidationException::withMessages(['zatca' => "تاريخ المستند {$data['number']} ({$data['issue_date']}) لاحق لتاريخ اليوم؛ الهيئة لا تقبل مستنداً ضريبياً بتاريخ مستقبلي."]);
        }
        $signed = $this->signDocument($data, $device, $icv, $previousHash, $company);

        $record = ZatcaDocument::create([
            'company_id' => $company->id,
            'zatca_device_id' => $device->id,
            'documentable_type' => $document->getMorphClass(),
            'documentable_id' => $document->getKey(),
            'document_number' => $data['number'],
            'type_code' => $data['type_code'],
            'subtype' => $data['subtype'],
            'uuid' => $data['uuid'],
            'icv' => $icv,
            'previous_hash' => $previousHash,
            'invoice_hash' => $signed['hash'],
            'signed_xml' => $signed['xml'],
            'qr' => $signed['qr'],
            'submission' => $data['subtype'] === DocumentMapper::STANDARD ? 'clearance' : 'reporting',
            'status' => 'pending',
        ]);

        $device->forceFill(['icv' => $icv, 'last_hash' => $signed['hash']])->save();

        $document->forceFill([
            'zatca_uuid' => $data['uuid'],
            'zatca_qr' => $signed['qr'],
            'zatca_hash' => $signed['hash'],
            'zatca_status' => 'pending',
        ])->saveQuietly();

        SubmitZatcaDocument::dispatch($record->id, $company->id)->afterCommit();

        return $record;
    }

    /**
     * Build + sign a document for a device (also used for compliance samples).
     */
    public function signDocument(array $data, ZatcaDevice $device, int $icv, string $previousHash, Company $company): array
    {
        $doc = $this->builder->build($data, $icv, $previousHash);
        $totals = $this->builder->totals($data['lines']);

        return $this->signer->sign($doc, $device->private_key, new Certificate($device->certificate()), [
            'seller_name' => $company->name,
            'vat_number' => $company->tax_number,
            'timestamp' => $data['issue_date'].'T'.$data['issue_time'],
            'total' => $totals['net'] + $totals['tax'],
            'vat' => $totals['tax'],
        ]);
    }

    /**
     * Signs a document again on the company's active device when ZATCA no
     * longer accepts the certificate that signed it (a replaced or stopped
     * device: HTTP 401). Allowed only while ZATCA has not accepted it. The
     * old record stays as "superseded"; the new one continues the active
     * device's chain (next ICV / PIH) with the same UUID and content.
     */
    public function restamp(ZatcaDocument $old): ZatcaDocument
    {
        $company = Company::findOrFail($old->company_id);
        $device = $this->device($company, $old->documentable?->branch_id ?? null);
        if (! $device) {
            throw ValidationException::withMessages(['zatca' => 'لا يوجد جهاز مفعّل لإعادة التوقيع عليه.']);
        }

        return DB::transaction(function () use ($old, $company, $device) {
            $claimed = ZatcaDocument::whereKey($old->id)->whereIn('status', ['pending', 'failed'])->lockForUpdate()->first();
            if (! $claimed || ! $claimed->documentable) {
                throw ValidationException::withMessages(['zatca' => 'يمكن إعادة توقيع المستندات التي لم تقبلها الهيئة بعد فقط.']);
            }
            $claimed->forceFill(['status' => 'superseded', 'errors' => array_merge($claimed->errors ?? [], [['message' => 'أُعيد توقيعه على الجهاز '.$device->name]])])->save();

            return $this->phaseTwo($claimed->documentable, $company, $device);
        });
    }

    /**
     * Whether a waiting document can only go through after restamp(): ZATCA
     * refused the certificate that signed it, and another device is active
     * now. A stopped device whose certificate ZATCA still accepts keeps
     * submitting its own documents; a refusal on the active device itself
     * is a certificate to renew, not a reason to sign again.
     */
    public function needsRestamp(ZatcaDocument $record): bool
    {
        $refused = in_array($record->status, ['pending', 'failed'], true)
            && collect($record->errors ?? [])->contains(fn ($e) => str_starts_with((string) ($e['message'] ?? ''), 'HTTP 401'));
        if (! $refused || ! ($company = Company::find($record->company_id))) {
            return false;
        }
        $active = $this->device($company, $record->documentable?->branch_id ?? null);

        return $active !== null && $active->id !== $record->zatca_device_id;
    }

    /**
     * Re-signs a document that ZATCA has not accepted yet when its signature
     * no longer fits: signed with a hex certificate serial (older versions)
     * or with a certificate the device has since replaced. The invoice hash
     * excludes the signature and the QR, so ICV / PIH and the chain stay as
     * they are; only the signature block and the QR are rebuilt.
     */
    public function resignIfStale(ZatcaDocument $record): bool
    {
        $device = $record->device;
        if (in_array($record->status, ['cleared', 'reported', 'warning', 'rejected'], true) || ! $device?->certificate() || ! $device->private_key) {
            return false;
        }

        $certificate = new Certificate($device->certificate());
        preg_match('#<ds:X509SerialNumber[^>]*>([^<]*)<#', $record->signed_xml, $serial);
        preg_match('#<ds:X509Certificate[^>]*>([^<]*)<#', $record->signed_xml, $embedded);
        if (($serial[1] ?? null) === $certificate->serialNumber() && preg_replace('/\s+/', '', $embedded[1] ?? '') === $certificate->base64()) {
            return false;
        }

        $doc = new \DOMDocument;
        $doc->loadXML($record->signed_xml);
        $extensions = $doc->getElementsByTagNameNS(UblInvoiceBuilder::NS['ext'], 'UBLExtensions')->item(0);
        while ($extensions?->firstChild) {
            $extensions->removeChild($extensions->firstChild);
        }

        $qr = Tlv::decode((string) $record->qr);
        $signed = $this->signer->sign($doc, $device->private_key, $certificate, [
            'seller_name' => $qr[1] ?? '', 'vat_number' => $qr[2] ?? '', 'timestamp' => $qr[3] ?? '', 'total' => $qr[4] ?? 0, 'vat' => $qr[5] ?? 0,
        ]);
        if ($signed['hash'] !== $record->invoice_hash) {
            return false; // never alter the content of a chained document
        }

        $record->forceFill(['signed_xml' => $signed['xml'], 'qr' => $signed['qr']])->save();
        $record->documentable?->forceFill(['zatca_qr' => $signed['qr']])->saveQuietly();

        return true;
    }

    /**
     * Apply a clearance / reporting outcome to the record and its document.
     */
    public function applyResult(ZatcaDocument $record, int $httpStatus, array $body): void
    {
        $warnings = $body['validationResults']['warningMessages'] ?? [];
        $errors = $body['validationResults']['errorMessages'] ?? [];

        $status = match (true) {
            in_array($httpStatus, [200, 202], true) && $record->submission === 'clearance' => ($body['clearanceStatus'] ?? 'CLEARED') === 'CLEARED' ? ($warnings ? 'warning' : 'cleared') : 'rejected',
            in_array($httpStatus, [200, 202], true) => ($body['reportingStatus'] ?? 'REPORTED') === 'REPORTED' ? ($warnings ? 'warning' : 'reported') : 'rejected',
            $httpStatus === 400 => 'rejected',
            default => 'failed',
        };

        $record->forceFill([
            'status' => $status,
            'warnings' => $warnings ?: null,
            'errors' => $errors ?: ($status === 'failed' ? [['message' => $body['message'] ?? "HTTP {$httpStatus}"]] : null),
            'cleared_xml' => isset($body['clearedInvoice']) ? base64_decode($body['clearedInvoice']) : $record->cleared_xml,
            'submitted_at' => now(),
        ])->save();

        // ZATCA re-stamps cleared standard invoices: the buyer's QR comes from the cleared copy.
        $qr = $record->qr;
        if ($record->cleared_xml && preg_match('#<cbc:ID>QR</cbc:ID>\s*<cac:Attachment>\s*<cbc:EmbeddedDocumentBinaryObject[^>]*>([^<]+)<#', $record->cleared_xml, $m)) {
            $qr = $m[1];
        }

        app(WebhookDispatcher::class)->dispatch('zatca.status_changed', [
            'document' => $record->document_number, 'type_code' => $record->type_code, 'status' => $status, 'uuid' => $record->uuid,
        ]);

        $record->documentable?->forceFill([
            'zatca_status' => in_array($status, ['cleared', 'reported', 'warning'], true) ? ($record->submission === 'clearance' ? 'cleared' : 'reported') : $status,
            'zatca_qr' => $qr,
        ])->saveQuietly();
    }

    /** What to do about the ZATCA answers users meet most (code, or text the message starts with). */
    public const HINTS = [
        'BR-KSA-100' => 'الرقم الضريبي للعميل غير مسجّل لدى الهيئة أو مكتوب خطأً. تحقق منه في خدمة «التحقق من الرقم الضريبي» بموقع الهيئة، وصحّحه في بطاقة العميل، ثم أصدر إشعاراً دائناً للفاتورة المرفوضة وفاتورة جديدة.',
        'BR-KSA-04' => 'تاريخ المستند لاحق لتاريخ اليوم، والهيئة لا تقبله. أصدر إشعاراً دائناً لهذه الفاتورة ثم فاتورة جديدة بتاريخ اليوم أو قبله.',
        'BR-KSA-44' => 'صيغة الرقم الضريبي للعميل غير صحيحة: 15 رقماً يبدأ وينتهي بالرقم 3.',
        'BR-KSA-63' => 'العنوان الوطني للعميل ناقص: أكمل رقم المبنى والشارع والحي والمدينة والرمز البريدي.',
        'BR-KSA-37' => 'رقم المبنى في العنوان الوطني يجب أن يكون 4 أرقام.',
        'BR-KSA-66' => 'الرمز البريدي يجب أن يكون 5 أرقام.',
        'XSD_ZATCA_INVALID' => 'خلل في بنية ملف الفاتورة. حدّث النظام ثم أعد الإرسال؛ إن تكرر أرسل الرسالة للدعم.',
        'HTTP 401' => 'لم تعد الهيئة تقبل شهادة الجهاز الذي وقّع هذا المستند. إن كان جهازاً سابقاً فاضغط «إعادة التوقيع على الجهاز الحالي»؛ وإن كان جهازك الحالي فجدّد شهادته، وتأكد أن بيئته (محاكاة / إنتاج) هي التي رُبط بها في بوابة فاتورة.',
        'HTTP 5' => 'خوادم الهيئة لا تستجيب حالياً؛ ستُعاد المحاولة تلقائياً.',
    ];

    /** The hints that apply to a document's errors. */
    public static function hints(?array $errors): array
    {
        $out = [];
        foreach ($errors ?? [] as $error) {
            foreach (self::HINTS as $key => $hint) {
                if (($error['code'] ?? null) === $key || str_starts_with((string) ($error['message'] ?? ''), $key)) {
                    $out[$key] = $hint;
                }
            }
        }

        return array_values($out);
    }

    /** Simplified documents older than this must already have been reported. */
    public static function reportingDeadline(): Carbon
    {
        return now()->subHours(config('zatca.reporting_deadline_hours', 24));
    }
}

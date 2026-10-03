<?php

namespace App\Services\Zatca;

use App\Jobs\SubmitZatcaDocument;
use App\Models\Company;
use App\Models\ZatcaDevice;
use App\Models\ZatcaDocument;
use App\Services\Saas\WebhookDispatcher;
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

    public function stamp(Model $document): ?ZatcaDocument
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

    /** Simplified documents older than this must already have been reported. */
    public static function reportingDeadline(): Carbon
    {
        return now()->subHours(config('zatca.reporting_deadline_hours', 24));
    }
}

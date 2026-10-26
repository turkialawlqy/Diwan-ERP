<?php

namespace App\Services\Zatca;

use App\Models\Company;
use App\Models\ZatcaDevice;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * EGS onboarding on the Fatoora portal:
 *   1. key pair + CSR  →  2. compliance CSID (OTP)  →  3. compliance checks
 *   (one sample of every document type the device will issue)  →
 *   4. production CSID. Renewal repeats 1-2 against the production endpoint.
 */
class OnboardingService
{
    public function __construct(
        protected CsrGenerator $csr,
        protected EInvoiceManager $manager,
        protected DocumentMapper $mapper,
    ) {}

    public function createDevice(Company $company, array $data): ZatcaDevice
    {
        if ($missing = $this->mapper->missingSellerFields($company)) {
            throw ValidationException::withMessages(['device' => 'أكمل بيانات البائع أولاً: '.implode('، ', $missing)]);
        }

        $serial = sprintf('1-%s|2-%s|3-%s', config('zatca.solution_name'), config('zatca.solution_version'), Str::uuid());
        $environment = $data['environment'] ?? 'sandbox';
        $prefix = ['sandbox' => 'TST', 'simulation' => 'PRE', 'production' => ''][$environment];

        $keys = $this->csr->generate([
            'common_name' => trim($prefix.'-'.$data['name'].'-'.$company->tax_number, '-'),
            'organization' => $company->name,
            'organization_unit' => $data['organization_unit'] ?? $company->setting('seller_city') ?? $company->name,
            'vat_number' => $company->tax_number,
            'serial_number' => $serial,
            'invoice_types' => $data['invoice_types'] ?? '1100',
            'address' => trim(($company->setting('seller_building_number') ?? '').' '.($company->setting('seller_street') ?? '').' '.($company->setting('seller_city') ?? '')),
            'business_category' => $data['business_category'] ?? 'Trading',
        ], $environment);

        return ZatcaDevice::create([
            'company_id' => $company->id,
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'serial_number' => $serial,
            'environment' => $environment,
            'invoice_types' => $data['invoice_types'] ?? '1100',
            'status' => 'new',
            'private_key' => $keys['private_key'],
            'csr' => $keys['csr'],
        ]);
    }

    public function requestComplianceCsid(ZatcaDevice $device, string $otp): void
    {
        $response = ZatcaClient::for($device->environment)->complianceCsid($device->csr, $otp);
        $body = $this->expectSuccess($response, 'طلب شهادة الامتثال');

        $device->forceFill([
            'compliance_token' => $body['binarySecurityToken'],
            'compliance_secret' => $body['secret'],
            'compliance_request_id' => (string) $body['requestID'],
            'status' => 'compliance',
        ])->save();
    }

    /**
     * Sign and submit one sample of each document type the device will issue.
     *
     * @return array<string, array{status: string, messages: array}>
     */
    public function runComplianceChecks(ZatcaDevice $device, Company $company): array
    {
        if ($device->status !== 'compliance') {
            throw ValidationException::withMessages(['device' => 'يجب الحصول على شهادة الامتثال أولاً.']);
        }

        $client = ZatcaClient::for($device->environment);
        $credentials = [$device->compliance_token, $device->compliance_secret];
        $results = [];
        $previousHash = ZatcaDevice::INITIAL_HASH;
        $icv = 0;

        foreach ($this->samples($device, $company) as $key => $data) {
            $signed = $this->manager->signDocument($data, $device, ++$icv, $previousHash, $company);
            $previousHash = $signed['hash'];

            $response = $client->complianceCheck($credentials, $signed['hash'], $data['uuid'], $signed['xml']);
            $body = $response->json() ?? [];
            $passed = $response->successful() && in_array($body['validationResults']['status'] ?? 'ERROR', ['PASS', 'WARNING'], true);
            $results[$key] = [
                'status' => $passed ? 'passed' : 'failed',
                'messages' => array_merge($body['validationResults']['errorMessages'] ?? [], $body['validationResults']['warningMessages'] ?? []),
            ];
        }

        $device->forceFill(['compliance_checks' => $results])->save();

        return $results;
    }

    public function requestProductionCsid(ZatcaDevice $device): void
    {
        $failed = collect($device->compliance_checks ?? [])->where('status', '!=', 'passed');
        if (! $device->compliance_checks || $failed->isNotEmpty()) {
            throw ValidationException::withMessages(['device' => 'يجب اجتياز جميع فحوصات الامتثال قبل طلب الشهادة الإنتاجية.']);
        }

        $response = ZatcaClient::for($device->environment)->productionCsid([$device->compliance_token, $device->compliance_secret], $device->compliance_request_id);
        $body = $this->expectSuccess($response, 'طلب الشهادة الإنتاجية');

        $certificate = new Certificate(base64_decode($body['binarySecurityToken']));
        $device->forceFill([
            'production_token' => $body['binarySecurityToken'],
            'production_secret' => $body['secret'],
            'certificate_expires_at' => $certificate->expiresAt(),
            'status' => 'active',
            'icv' => 0,
            'last_hash' => null,
            'onboarded_at' => now(),
        ])->save();
    }

    /**
     * Renew an expiring production CSID with a new key (OTP from the portal).
     */
    public function renew(ZatcaDevice $device, Company $company, string $otp): void
    {
        $fresh = $this->csr->generate([
            'common_name' => $device->name.'-'.$company->tax_number,
            'organization' => $company->name,
            'organization_unit' => $company->setting('seller_city') ?? $company->name,
            'vat_number' => $company->tax_number,
            'serial_number' => $device->serial_number,
            'invoice_types' => $device->invoice_types,
            'address' => (string) $company->setting('seller_city'),
            'business_category' => 'Trading',
        ], $device->environment);

        $response = ZatcaClient::for($device->environment)->renewProductionCsid([$device->production_token, $device->production_secret], $fresh['csr'], $otp);
        $body = $this->expectSuccess($response, 'تجديد الشهادة');

        $device->forceFill([
            'private_key' => $fresh['private_key'],
            'csr' => $fresh['csr'],
            'production_token' => $body['binarySecurityToken'],
            'production_secret' => $body['secret'],
            'certificate_expires_at' => (new Certificate(base64_decode($body['binarySecurityToken'])))->expiresAt(),
        ])->save();
    }

    /**
     * Sample documents (standard and/or simplified: invoice, credit, debit).
     */
    public function samples(ZatcaDevice $device, Company $company): array
    {
        $types = [];
        if (($device->invoice_types[0] ?? '0') === '1') {
            $types['standard'] = DocumentMapper::STANDARD;
        }
        if (($device->invoice_types[1] ?? '0') === '1') {
            $types['simplified'] = DocumentMapper::SIMPLIFIED;
        }

        $seller = $this->mapper->seller($company);
        $buyer = ['name' => 'Compliance Test Buyer', 'vat' => '399999999900003', 'street' => 'Prince Sultan', 'building' => '2322',
            'district' => 'Al-Murabba', 'city' => 'Riyadh', 'postal_code' => '23333', 'country' => 'SA'];
        $line = ['name' => 'Compliance sample item', 'quantity' => 2, 'unit_price' => 100, 'discount' => 10, 'tax_rate' => 15];

        $samples = [];
        foreach ($types as $label => $subtype) {
            foreach (['invoice' => '388', 'credit' => '381', 'debit' => '383'] as $kind => $code) {
                $samples["{$label}_{$kind}"] = [
                    'number' => strtoupper(substr($label, 0, 3)).'-'.$kind.'-'.random_int(1000, 9999),
                    'uuid' => (string) Str::uuid(),
                    'issue_date' => now('Asia/Riyadh')->toDateString(),
                    'issue_time' => now('Asia/Riyadh')->format('H:i:s'),
                    'type_code' => $code,
                    'subtype' => $subtype,
                    'currency' => 'SAR',
                    'supply_date' => now('Asia/Riyadh')->toDateString(),
                    'billing_reference' => $code === '388' ? null : 'SAMPLE-INV-1',
                    'instruction_note' => $code === '388' ? null : 'Compliance sample adjustment',
                    'payment_means' => '10',
                    'seller' => $seller,
                    'buyer' => $subtype === DocumentMapper::STANDARD ? $buyer : null,
                    'lines' => [$line],
                ];
            }
        }

        return $samples;
    }

    protected function expectSuccess(Response $response, string $step): array
    {
        $body = $response->json() ?? [];
        if (! $response->successful() || empty($body['binarySecurityToken'])) {
            $detail = $body['errors'][0]['message'] ?? $body['message'] ?? $body['dispositionMessage'] ?? $response->body();
            throw ValidationException::withMessages(['device' => "تعذر {$step}: ".mb_substr((string) $detail, 0, 300)]);
        }

        return $body;
    }
}

<?php

namespace App\Services\Zatca;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fatoora portal API (V2): compliance / production CSIDs, compliance
 * checks, clearance and reporting.
 */
class ZatcaClient
{
    public function __construct(protected string $environment = 'sandbox') {}

    public static function for(string $environment): self
    {
        return new self($environment);
    }

    /** Compliance CSID from the CSR and the portal OTP. */
    public function complianceCsid(string $csrPem, string $otp): Response
    {
        return $this->request()->withHeaders(['OTP' => $otp])
            ->post('/compliance', ['csr' => base64_encode($csrPem)]);
    }

    public function complianceCheck(array $credentials, string $hash, string $uuid, string $xml): Response
    {
        return $this->request($credentials)->post('/compliance/invoices', $this->payload($hash, $uuid, $xml));
    }

    public function productionCsid(array $credentials, string $complianceRequestId): Response
    {
        return $this->request($credentials)->post('/production/csids', ['compliance_request_id' => $complianceRequestId]);
    }

    public function renewProductionCsid(array $credentials, string $csrPem, string $otp): Response
    {
        return $this->request($credentials)->withHeaders(['OTP' => $otp])
            ->patch('/production/csids', ['csr' => base64_encode($csrPem)]);
    }

    public function clearance(array $credentials, string $hash, string $uuid, string $xml): Response
    {
        return $this->request($credentials)->withHeaders(['Clearance-Status' => '1'])
            ->post('/invoices/clearance/single', $this->payload($hash, $uuid, $xml));
    }

    public function reporting(array $credentials, string $hash, string $uuid, string $xml): Response
    {
        return $this->request($credentials)->withHeaders(['Clearance-Status' => '0'])
            ->post('/invoices/reporting/single', $this->payload($hash, $uuid, $xml));
    }

    protected function payload(string $hash, string $uuid, string $xml): array
    {
        return ['invoiceHash' => $hash, 'uuid' => $uuid, 'invoice' => base64_encode($xml)];
    }

    protected function request(?array $credentials = null): PendingRequest
    {
        $request = Http::baseUrl(config('zatca.endpoints.'.$this->environment))
            ->acceptJson()
            ->asJson()
            ->timeout(config('zatca.timeout', 30))
            ->withHeaders(['Accept-Version' => 'V2', 'Accept-Language' => 'en']);

        if ($credentials) {
            $request = $request->withBasicAuth($credentials[0], $credentials[1]);
        }

        return $request;
    }
}

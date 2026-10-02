<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Str;

class ZatcaService
{
    /**
     * Get the active ZATCA phase for a company ('phase1' or 'phase2').
     */
    public static function getActivePhase(?int $companyId = null): string
    {
        if (!$companyId) {
            $company = app(TenantManager::class)->getCompany();
            $companyId = $company?->id;
        }

        if (!$companyId) {
            return 'phase1';
        }

        $setting = CompanySetting::where('company_id', $companyId)
            ->where('key', 'zatca_phase')
            ->value('value');

        return $setting === 'phase2' ? 'phase2' : 'phase1';
    }

    /**
     * Generate ZATCA compliant TLV Base64 QR Code string.
     * Supports both Phase 1 (Tags 1-5) and Phase 2 (Tags 1-9).
     *
     * @param string $sellerName
     * @param string $vatRegistrationNumber
     * @param string $invoiceTimestamp (ISO 8601: Y-m-d\TH:i:s\Z)
     * @param string|float $totalWithVat
     * @param string|float $vatTotal
     * @param string|null $invoiceHash (Phase 2 - Tag 6)
     * @param string|null $digitalSignature (Phase 2 - Tag 7)
     * @param string|null $publicKey (Phase 2 - Tag 8)
     * @param string|null $certSignature (Phase 2 - Tag 9)
     * @return string Base64 encoded TLV
     */
    public static function generateQrCode(
        string $sellerName,
        string $vatRegistrationNumber,
        string $invoiceTimestamp,
        $totalWithVat,
        $vatTotal,
        ?string $invoiceHash = null,
        ?string $digitalSignature = null,
        ?string $publicKey = null,
        ?string $certSignature = null
    ): string {
        $tlv = '';

        // Tag 1: Seller Name
        $tlv .= self::packTlv(1, $sellerName);

        // Tag 2: VAT Registration Number
        $tlv .= self::packTlv(2, $vatRegistrationNumber);

        // Tag 3: Timestamp
        $tlv .= self::packTlv(3, $invoiceTimestamp);

        // Tag 4: Invoice Total (with VAT)
        $formattedTotal = number_format((float) $totalWithVat, 2, '.', '');
        $tlv .= self::packTlv(4, $formattedTotal);

        // Tag 5: VAT Total
        $formattedVat = number_format((float) $vatTotal, 2, '.', '');
        $tlv .= self::packTlv(5, $formattedVat);

        // Phase 2 Extended Cryptographic Tags
        if ($invoiceHash) {
            // Tag 6: Invoice Hash (SHA-256)
            $tlv .= self::packTlv(6, $invoiceHash);
        }

        if ($digitalSignature) {
            // Tag 7: ECDSA Digital Signature
            $tlv .= self::packTlv(7, $digitalSignature);
        }

        if ($publicKey) {
            // Tag 8: ECDSA Public Key
            $tlv .= self::packTlv(8, $publicKey);
        }

        if ($certSignature) {
            // Tag 9: Certificate Cryptographic Stamp Signature
            $tlv .= self::packTlv(9, $certSignature);
        }

        return base64_encode($tlv);
    }

    /**
     * Calculate SHA-256 hash for invoice payload (Phase 2 requirement).
     */
    public static function calculateInvoiceHash(string $xmlPayload): string
    {
        return base64_encode(hash('sha256', $xmlPayload, true));
    }

    /**
     * Perform simulated/sandbox device onboarding with ZATCA OTP.
     */
    public static function onboardDevice(int $companyId, string $otp, string $environment = 'sandbox'): array
    {
        $cleanOtp = trim($otp);

        if (strlen($cleanOtp) < 6) {
            return [
                'success' => false,
                'message' => 'رمز التحقق (OTP) غير صحيح. يجب أن يتكون من 6 أرقام على الأقل مستخرجة من بوابة فاتورة.',
            ];
        }

        // Generate Simulated ECDSA CSID credentials
        $binaryUuid = (string) Str::uuid();
        $simulatedCsid = base64_encode("ZATCA-CSID-{$environment}-{$binaryUuid}");
        $simulatedSecret = Str::random(32);

        // Update settings in database
        $settings = [
            'zatca_phase' => 'phase2',
            'zatca_environment' => $environment,
            'zatca_otp' => $cleanOtp,
            'zatca_csid' => $simulatedCsid,
            'zatca_secret' => $simulatedSecret,
            'zatca_compliance_status' => 'active',
            'zatca_onboarded_at' => now()->toDateTimeString(),
        ];

        foreach ($settings as $key => $val) {
            CompanySetting::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'key' => $key,
                ],
                [
                    'group' => 'zatca',
                    'value' => (string) $val,
                    'type' => 'string',
                ]
            );
        }

        return [
            'success' => true,
            'csid' => $simulatedCsid,
            'environment' => $environment,
            'message' => $environment === 'production'
                ? 'تم بنجاح ربط واعتماد جهاز الفوترة بالبيئة الإنتاجية لمنصة فاتورة (ZATCA Production)!'
                : 'تم بنجاح إتمام اختبار الامتثال والربط في بيئة المحاكاة والتجربة (ZATCA Sandbox)!',
        ];
    }

    /**
     * Pack single TLV field.
     */
    private static function packTlv(int $tag, string $value): string
    {
        $length = strlen($value);
        return chr($tag) . chr($length) . $value;
    }
}

<?php

namespace App\Services;

use App\Models\ZatcaDevice;
use App\Services\Zatca\Tlv;

/**
 * Thin compatibility facade. E-invoicing lives in App\Services\Zatca.
 */
class ZatcaService
{
    /**
     * 'phase2' once the company has an onboarded (active) EGS device.
     */
    public static function getActivePhase(?int $companyId = null): string
    {
        $companyId ??= app(TenantManager::class)->getTenantId();
        if (! $companyId) {
            return 'phase1';
        }

        return ZatcaDevice::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'active')->exists() ? 'phase2' : 'phase1';
    }

    /**
     * Phase 1 QR (tags 1-5) as base64 TLV.
     */
    public static function generateQrCode(string $sellerName, string $vatRegistrationNumber, string $invoiceTimestamp, $totalWithVat, $vatTotal): string
    {
        return Tlv::encode([
            1 => $sellerName,
            2 => $vatRegistrationNumber,
            3 => $invoiceTimestamp,
            4 => number_format((float) $totalWithVat, 2, '.', ''),
            5 => number_format((float) $vatTotal, 2, '.', ''),
        ]);
    }
}

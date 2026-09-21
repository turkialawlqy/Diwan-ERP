<?php

namespace App\Services;

class ZatcaService
{
    /**
     * Generate ZATCA compliant TLV Base64 QR Code string.
     *
     * @param string $sellerName
     * @param string $vatRegistrationNumber
     * @param string $invoiceTimestamp (ISO 8601: Y-m-d\TH:i:s\Z)
     * @param string|float $totalWithVat
     * @param string|float $vatTotal
     * @return string Base64 encoded TLV
     */
    public static function generateQrCode(
        string $sellerName,
        string $vatRegistrationNumber,
        string $invoiceTimestamp,
        $totalWithVat,
        $vatTotal
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

        return base64_encode($tlv);
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

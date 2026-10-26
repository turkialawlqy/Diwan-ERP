<?php

namespace App\Services\Zatca;

use RuntimeException;

/**
 * Reads what the signature and the QR code need from the CSID certificate.
 */
class Certificate
{
    protected string $der;

    protected array $parsed;

    /** @param  string  $base64  base64 DER (the decoded binary security token) */
    public function __construct(protected string $base64)
    {
        $this->base64 = preg_replace('/\s+/', '', $base64);
        $this->der = base64_decode($this->base64, true) ?: throw new RuntimeException('Invalid certificate encoding.');
        $this->parsed = openssl_x509_parse($this->pem()) ?: throw new RuntimeException('Unreadable certificate.');
    }

    public function base64(): string
    {
        return $this->base64;
    }

    public function pem(): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split($this->base64, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /** base64( hex( sha256(base64 certificate) ) ) — as the XAdES CertDigest expects. */
    public function hash(): string
    {
        return base64_encode(hash('sha256', $this->base64));
    }

    /** "CN=..., DC=..., DC=..." (most specific first). */
    public function issuer(): string
    {
        $parts = [];
        foreach ($this->parsed['issuer'] as $key => $value) {
            foreach ((array) $value as $v) {
                $parts[] = "{$key}={$v}";
            }
        }

        return implode(', ', array_reverse($parts));
    }

    /**
     * Decimal serial number, as xs:integer in X509SerialNumber requires.
     * OpenSSL prints serials too long for an integer (every ZATCA CSID) in
     * hex with a "0x" prefix.
     */
    public function serialNumber(): string
    {
        $serial = (string) $this->parsed['serialNumber'];
        if (! empty($this->parsed['serialNumberHex'])) {
            return self::hexToDecimal($this->parsed['serialNumberHex']);
        }

        return str_starts_with(strtolower($serial), '0x') ? self::hexToDecimal(substr($serial, 2)) : $serial;
    }

    /** Arbitrary-length hex to decimal string (no gmp / bcmath needed). */
    public static function hexToDecimal(string $hex): string
    {
        $digits = [0]; // decimal digits, least significant first
        foreach (str_split(strtolower(ltrim($hex, '0')) ?: '0') as $char) {
            $carry = hexdec($char);
            foreach ($digits as $i => $digit) {
                $value = $digit * 16 + $carry;
                $digits[$i] = $value % 10;
                $carry = intdiv($value, 10);
            }
            while ($carry > 0) {
                $digits[] = $carry % 10;
                $carry = intdiv($carry, 10);
            }
        }

        return implode('', array_reverse($digits));
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return isset($this->parsed['validTo_time_t']) ? (new \DateTimeImmutable)->setTimestamp($this->parsed['validTo_time_t']) : null;
    }

    /** DER SubjectPublicKeyInfo (QR tag 8). */
    public function publicKey(): string
    {
        $pem = openssl_pkey_get_details(openssl_pkey_get_public($this->pem()))['key'];

        return base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
    }

    /** The issuing CA's signature over the certificate (QR tag 9). */
    public function signature(): string
    {
        // Certificate ::= SEQUENCE { tbsCertificate, signatureAlgorithm, signatureValue BIT STRING }
        [, $offset] = Der::header($this->der, 0);
        foreach ([0, 1] as $skip) {
            [$length, $contentOffset] = Der::header($this->der, $offset);
            $offset = $contentOffset + $length;
        }
        [$length, $contentOffset] = Der::header($this->der, $offset);

        return substr($this->der, $contentOffset + 1, $length - 1); // drop "unused bits" byte
    }
}

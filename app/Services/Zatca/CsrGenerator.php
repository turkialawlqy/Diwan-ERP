<?php

namespace App\Services\Zatca;

use RuntimeException;

/**
 * EGS key pair (ECDSA secp256k1) and the certificate signing request in the
 * format the Fatoora portal requires.
 */
class CsrGenerator
{
    /**
     * @param  array  $subject  common_name, organization, organization_unit, vat_number, serial_number,
     *                          invoice_types (e.g. 1100), address, business_category
     * @return array{private_key: string, csr: string} PEM strings
     */
    public function generate(array $subject, string $environment): array
    {
        // Every OpenSSL call gets our own config file: on Windows (Laragon,
        // XAMPP) PHP often has no default openssl.cnf and key generation
        // fails with "system library::No such process" without one.
        $config = tempnam(sys_get_temp_dir(), 'zatca-csr');
        file_put_contents($config, $this->config($subject, $environment));
        $options = ['config' => $config, 'digest_alg' => 'sha256'];

        try {
            $this->clearErrors();
            $key = openssl_pkey_new($options + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1'])
                ?: throw new RuntimeException('Could not generate the EGS key: '.$this->errors());

            $csr = openssl_csr_new([
                'countryName' => 'SA',
                'organizationalUnitName' => $this->clean($subject['organization_unit']),
                'organizationName' => $this->clean($subject['organization']),
                'commonName' => $this->clean($subject['common_name']),
            ], $key, $options + ['req_extensions' => 'v3_req']);

            if (! $csr) {
                throw new RuntimeException('Could not create the CSR: '.$this->errors());
            }

            openssl_csr_export($csr, $csrPem);
            openssl_pkey_export($key, $keyPem, null, $options)
                ?: throw new RuntimeException('Could not export the EGS key: '.$this->errors());
        } finally {
            @unlink($config);
        }

        return ['private_key' => $keyPem, 'csr' => $csrPem];
    }

    protected function config(array $s, string $environment): string
    {
        $template = config('zatca.certificate_templates.'.$environment, 'TSTZATCA-Code-Signing');
        $values = [
            'SN' => $s['serial_number'],
            'UID' => $s['vat_number'],
            'title' => $s['invoice_types'] ?? '1100',
            'registeredAddress' => $s['address'],
            'businessCategory' => $s['business_category'],
        ];
        $altNames = implode("\n", array_map(fn ($k, $v) => "{$k} = ".$this->clean($v), array_keys($values), $values));

        return <<<CNF
oid_section = OIDs
[ OIDs ]
certificateTemplateName = 1.3.6.1.4.1.311.20.2
[ req ]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
req_extensions = v3_req
[ dn ]
CN = egs
[ v3_req ]
1.3.6.1.4.1.311.20.2 = ASN1:PRINTABLESTRING:{$template}
subjectAltName = dirName:alt_names
[ alt_names ]
{$altNames}
CNF;
    }

    protected function clearErrors(): void
    {
        while (openssl_error_string() !== false);
    }

    /** All queued OpenSSL errors; the first one alone is often unhelpful. */
    protected function errors(): string
    {
        $errors = [];
        while (($e = openssl_error_string()) !== false) {
            $errors[] = $e;
        }

        return implode(' | ', $errors) ?: 'unknown OpenSSL error';
    }

    /** Values go into an OpenSSL config file: no newlines or section syntax. */
    protected function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n", '[', ']', '$'], ' ', $value));
    }
}

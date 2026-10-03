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
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1'])
            ?: throw new RuntimeException('Could not generate the EGS key: '.openssl_error_string());

        $config = tempnam(sys_get_temp_dir(), 'zatca-csr');
        file_put_contents($config, $this->config($subject, $environment));

        try {
            $csr = openssl_csr_new([
                'countryName' => 'SA',
                'organizationalUnitName' => $this->clean($subject['organization_unit']),
                'organizationName' => $this->clean($subject['organization']),
                'commonName' => $this->clean($subject['common_name']),
            ], $key, ['config' => $config, 'req_extensions' => 'v3_req', 'digest_alg' => 'sha256']);

            if (! $csr) {
                throw new RuntimeException('Could not create the CSR: '.openssl_error_string());
            }

            openssl_csr_export($csr, $csrPem);
            openssl_pkey_export($key, $keyPem);
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

    /** Values go into an OpenSSL config file: no newlines or section syntax. */
    protected function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n", '[', ']', '$'], ' ', $value));
    }
}

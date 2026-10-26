<?php

namespace App\Services\Zatca;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Signs a UBL document the way ZATCA validates it:
 *  1. invoice hash = SHA-256 of the C14N document without UBLExtensions,
 *     cac:Signature and the QR reference (the XPath transforms of the signature);
 *  2. ECDSA (secp256k1, SHA-256) signature of that hash with the EGS private key;
 *  3. XAdES-BES signed properties carrying the certificate digest;
 *  4. QR code with tags 1-9.
 */
class InvoiceSigner
{
    protected const DS = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * @return array{xml: string, hash: string, signature: string, qr: string}
     */
    public function sign(DOMDocument $doc, string $privateKeyPem, Certificate $certificate, array $qrFields, ?string $signingTime = null): array
    {
        $hash = $this->hash($doc);

        $key = openssl_pkey_get_private($privateKeyPem) ?: throw new RuntimeException('Invalid EGS private key.');
        if (! openssl_sign(base64_decode($hash), $rawSignature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing failed: '.openssl_error_string());
        }
        $signature = base64_encode($rawSignature);

        $signingTime ??= gmdate('Y-m-d\TH:i:s');
        $signedPropertiesForHash = $this->signedProperties($signingTime, $certificate, true);
        $signedPropertiesHash = base64_encode(hash('sha256', $signedPropertiesForHash));

        $qr = Tlv::encode([
            1 => $qrFields['seller_name'],
            2 => $qrFields['vat_number'],
            3 => $qrFields['timestamp'],
            4 => number_format((float) $qrFields['total'], 2, '.', ''),
            5 => number_format((float) $qrFields['vat'], 2, '.', ''),
            6 => $hash,
            7 => $signature,
            8 => $certificate->publicKey(),
            9 => $certificate->signature(),
        ]);

        $xpath = $this->xpath($doc);
        $xpath->query('//cac:AdditionalDocumentReference[cbc:ID="QR"]/cac:Attachment/cbc:EmbeddedDocumentBinaryObject')->item(0)->nodeValue = $qr;

        $extensions = $xpath->query('/inv:Invoice/ext:UBLExtensions')->item(0);
        $fragment = $doc->createDocumentFragment();
        $fragment->appendXML($this->extensionXml($hash, $signedPropertiesHash, $signature, $certificate, $this->signedProperties($signingTime, $certificate, false)));
        $extensions->appendChild($fragment);

        return ['xml' => $doc->saveXML(), 'hash' => $hash, 'signature' => $signature, 'qr' => $qr];
    }

    /**
     * Invoice hash (base64 SHA-256) of the document minus the signature parts.
     */
    public function hash(DOMDocument $doc): string
    {
        $copy = new DOMDocument;
        $copy->loadXML($doc->saveXML());
        $xpath = $this->xpath($copy);

        foreach (['//ext:UBLExtensions', '//cac:Signature', '//cac:AdditionalDocumentReference[cbc:ID="QR"]'] as $query) {
            foreach (iterator_to_array($xpath->query($query)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        return base64_encode(hash('sha256', $copy->documentElement->C14N(false, false), true));
    }

    /**
     * Verifies a signed document: hash, ECDSA signature against the
     * certificate, and the QR hash. Used by tests and before resubmitting.
     */
    public function verify(string $xml): bool
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xpath = $this->xpath($doc);
        $xpath->registerNamespace('ds', self::DS);

        $digest = $xpath->query('//ds:Reference[@Id="invoiceSignedData"]/ds:DigestValue')->item(0)?->nodeValue;
        $signature = $xpath->query('//ds:SignatureValue')->item(0)?->nodeValue;
        $certificate = $xpath->query('//ds:X509Certificate')->item(0)?->nodeValue;
        if (! $digest || ! $signature || ! $certificate || $digest !== $this->hash($doc)) {
            return false;
        }

        $publicKey = openssl_pkey_get_public((new Certificate($certificate))->pem());

        return openssl_verify(base64_decode($digest), base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    protected function xpath(DOMDocument $doc): DOMXPath
    {
        $xpath = new DOMXPath($doc);
        foreach (UblInvoiceBuilder::NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    /**
     * The SignedProperties block. ZATCA digests it in this exact textual form
     * (namespaces declared on each ds element, fixed indentation).
     */
    protected function signedProperties(string $signingTime, Certificate $certificate, bool $forHashing): string
    {
        $ds = $forHashing ? ' xmlns:ds="http://www.w3.org/2000/09/xmldsig#"' : '';
        $xades = $forHashing ? ' xmlns:xades="http://uri.etsi.org/01903/v1.3.2#"' : '';
        $hash = $certificate->hash();
        $issuer = htmlspecialchars($certificate->issuer(), ENT_XML1);
        $serial = $certificate->serialNumber();

        return <<<XML
<xades:SignedProperties{$xades} Id="xadesSignedProperties">
                                    <xades:SignedSignatureProperties>
                                        <xades:SigningTime>{$signingTime}</xades:SigningTime>
                                        <xades:SigningCertificate>
                                            <xades:Cert>
                                                <xades:CertDigest>
                                                    <ds:DigestMethod{$ds} Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                                    <ds:DigestValue{$ds}>{$hash}</ds:DigestValue>
                                                </xades:CertDigest>
                                                <xades:IssuerSerial>
                                                    <ds:X509IssuerName{$ds}>{$issuer}</ds:X509IssuerName>
                                                    <ds:X509SerialNumber{$ds}>{$serial}</ds:X509SerialNumber>
                                                </xades:IssuerSerial>
                                            </xades:Cert>
                                        </xades:SigningCertificate>
                                    </xades:SignedSignatureProperties>
                                </xades:SignedProperties>
XML;
    }

    protected function extensionXml(string $hash, string $propertiesHash, string $signature, Certificate $certificate, string $signedProperties): string
    {
        $cert = $certificate->base64();

        return <<<XML
<ext:UBLExtension xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
        <ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>
        <ext:ExtensionContent>
            <sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2" xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2" xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">
                <sac:SignatureInformation>
                    <cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>
                    <sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>
                    <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">
                        <ds:SignedInfo>
                            <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                            <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
                            <ds:Reference Id="invoiceSignedData" URI="">
                                <ds:Transforms>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                                </ds:Transforms>
                                <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                <ds:DigestValue>{$hash}</ds:DigestValue>
                            </ds:Reference>
                            <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">
                                <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                <ds:DigestValue>{$propertiesHash}</ds:DigestValue>
                            </ds:Reference>
                        </ds:SignedInfo>
                        <ds:SignatureValue>{$signature}</ds:SignatureValue>
                        <ds:KeyInfo>
                            <ds:X509Data>
                                <ds:X509Certificate>{$cert}</ds:X509Certificate>
                            </ds:X509Data>
                        </ds:KeyInfo>
                        <ds:Object>
                            <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">
                                {$signedProperties}
                            </xades:QualifyingProperties>
                        </ds:Object>
                    </ds:Signature>
                </sac:SignatureInformation>
            </sig:UBLDocumentSignatures>
        </ext:ExtensionContent>
    </ext:UBLExtension>
XML;
    }
}

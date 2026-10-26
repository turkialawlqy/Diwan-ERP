<?php

namespace App\Services\Zatca;

use DOMDocument;
use DOMElement;
use Illuminate\Validation\ValidationException;

/**
 * Builds the UBL 2.1 XML (KSA profile) of an invoice, credit note or debit
 * note. The document comes out with empty UBLExtensions / Signature / QR
 * placeholders in their final positions; InvoiceSigner fills them.
 *
 * $data keys: number, uuid, issue_date (Y-m-d), issue_time (H:i:s), type_code (388|381|383),
 * subtype (0100000|0200000), currency, supply_date?, billing_reference?, instruction_note?,
 * payment_means (10 cash, 30 credit, 42 bank, 48 card, 1 other), seller[], buyer[]|null, lines[]
 * line: name, quantity, unit_price, discount, tax_rate, tax_category?, exemption_code?, exemption_reason?
 */
class UblInvoiceBuilder
{
    public const NS = [
        'inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
    ];

    protected DOMDocument $doc;

    protected string $currency;

    protected float $rate = 1.0;

    public function build(array $data, int $icv, string $previousHash): DOMDocument
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->currency = $data['currency'] ?? 'SAR';
        $this->rate = (float) ($data['exchange_rate'] ?? 1) ?: 1.0;
        $totals = $this->totals($data['lines']);

        $root = $this->doc->createElementNS(self::NS['inv'], 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS['cac']);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS['cbc']);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::NS['ext']);
        $this->doc->appendChild($root);

        $root->appendChild($this->doc->createElementNS(self::NS['ext'], 'ext:UBLExtensions'));
        $this->cbc($root, 'ProfileID', 'reporting:1.0');
        $this->cbc($root, 'ID', $data['number']);
        $this->cbc($root, 'UUID', $data['uuid']);
        $this->cbc($root, 'IssueDate', $data['issue_date']);
        $this->cbc($root, 'IssueTime', $data['issue_time']);
        $this->cbc($root, 'InvoiceTypeCode', $data['type_code'], ['name' => $data['subtype']]);
        $this->cbc($root, 'DocumentCurrencyCode', $this->currency);
        $this->cbc($root, 'TaxCurrencyCode', 'SAR');

        if (! empty($data['billing_reference'])) {
            $ref = $this->cac($root, 'BillingReference');
            $this->cbc($this->cac($ref, 'InvoiceDocumentReference'), 'ID', $data['billing_reference']);
        }

        $icvRef = $this->cac($root, 'AdditionalDocumentReference');
        $this->cbc($icvRef, 'ID', 'ICV');
        $this->cbc($icvRef, 'UUID', (string) $icv);

        $this->attachment($root, 'PIH', $previousHash);
        $this->attachment($root, 'QR', '');

        $signature = $this->cac($root, 'Signature');
        $this->cbc($signature, 'ID', 'urn:oasis:names:specification:ubl:signature:Invoice');
        $this->cbc($signature, 'SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');

        $this->party($this->cac($root, 'AccountingSupplierParty'), $data['seller'], true);
        $this->party($this->cac($root, 'AccountingCustomerParty'), $data['buyer'] ?? [], false);

        if (! empty($data['supply_date'])) {
            $this->cbc($this->cac($root, 'Delivery'), 'ActualDeliveryDate', $data['supply_date']);
        }

        $means = $this->cac($root, 'PaymentMeans');
        $this->cbc($means, 'PaymentMeansCode', (string) ($data['payment_means'] ?? '10'));
        if (! empty($data['instruction_note'])) {
            $this->cbc($means, 'InstructionNote', $data['instruction_note']);
        }

        // Tax totals: the first in the tax currency (SAR, converted at the
        // document rate for foreign-currency invoices), the second in the
        // invoice currency with the per-category breakdown.
        $this->cbc($this->cac($root, 'TaxTotal'), 'TaxAmount', number_format(round($totals['tax'] * $this->rate, 2), 2, '.', ''), ['currencyID' => 'SAR']);
        $taxTotal = $this->cac($root, 'TaxTotal');
        $this->amount($taxTotal, 'TaxAmount', $totals['tax']);
        foreach ($totals['categories'] as $category) {
            $subtotal = $this->cac($taxTotal, 'TaxSubtotal');
            $this->amount($subtotal, 'TaxableAmount', $category['taxable']);
            $this->amount($subtotal, 'TaxAmount', $category['tax']);
            $this->taxCategory($subtotal, 'TaxCategory', $category);
        }

        $monetary = $this->cac($root, 'LegalMonetaryTotal');
        $this->amount($monetary, 'LineExtensionAmount', $totals['net']);
        $this->amount($monetary, 'TaxExclusiveAmount', $totals['net']);
        $this->amount($monetary, 'TaxInclusiveAmount', $totals['net'] + $totals['tax']);
        $this->amount($monetary, 'AllowanceTotalAmount', 0);
        $this->amount($monetary, 'PrepaidAmount', 0);
        $this->amount($monetary, 'PayableAmount', $totals['net'] + $totals['tax']);

        foreach ($totals['lines'] as $i => $line) {
            $this->line($root, $i + 1, $line);
        }

        return $this->doc;
    }

    /**
     * Line nets / taxes and document totals per tax category (VAT rounded per category).
     */
    public function totals(array $lines): array
    {
        $out = ['lines' => [], 'categories' => [], 'net' => 0.0, 'tax' => 0.0];

        foreach ($lines as $line) {
            $quantity = (float) $line['quantity'];
            $price = (float) $line['unit_price'];
            $discount = round((float) ($line['discount'] ?? 0), 2);
            $rate = (float) ($line['tax_rate'] ?? 15);
            $net = round($quantity * $price - $discount, 2);
            $tax = round($net * $rate / 100, 2);
            $category = $line['tax_category'] ?? ($rate > 0 ? 'S' : 'Z');

            $out['lines'][] = $line + compact('quantity', 'price', 'discount', 'rate', 'net', 'tax', 'category');
            $key = $category.'|'.number_format($rate, 2).'|'.($line['exemption_code'] ?? '');
            $out['categories'][$key] ??= ['id' => $category, 'rate' => $rate, 'taxable' => 0.0, 'tax' => 0.0,
                'exemption_code' => $line['exemption_code'] ?? null, 'exemption_reason' => $line['exemption_reason'] ?? null];
            $out['categories'][$key]['taxable'] += $net;
            $out['net'] += $net;
        }

        foreach ($out['categories'] as &$category) {
            $category['taxable'] = round($category['taxable'], 2);
            $category['tax'] = round($category['taxable'] * $category['rate'] / 100, 2);
            $out['tax'] += $category['tax'];
        }
        $out['net'] = round($out['net'], 2);
        $out['tax'] = round($out['tax'], 2);

        return $out;
    }

    protected function line(DOMElement $root, int $id, array $line): void
    {
        $el = $this->cac($root, 'InvoiceLine');
        $this->cbc($el, 'ID', (string) $id);
        $this->cbc($el, 'InvoicedQuantity', $this->quantity($line['quantity']), ['unitCode' => $line['unit_code'] ?? 'PCE']);
        $this->amount($el, 'LineExtensionAmount', $line['net']);

        if ($line['discount'] > 0) {
            $allowance = $this->cac($el, 'AllowanceCharge');
            $this->cbc($allowance, 'ChargeIndicator', 'false');
            $this->cbc($allowance, 'AllowanceChargeReason', 'discount');
            $this->amount($allowance, 'Amount', $line['discount']);
        }

        $tax = $this->cac($el, 'TaxTotal');
        $this->amount($tax, 'TaxAmount', $line['tax']);
        $this->amount($tax, 'RoundingAmount', $line['net'] + $line['tax']);

        $item = $this->cac($el, 'Item');
        $this->cbc($item, 'Name', mb_substr((string) $line['name'], 0, 1000));
        $this->taxCategory($item, 'ClassifiedTaxCategory', ['id' => $line['category'], 'rate' => $line['rate'],
            'exemption_code' => $line['exemption_code'] ?? null, 'exemption_reason' => $line['exemption_reason'] ?? null], false);

        $price = $this->cac($el, 'Price');
        $this->amount($price, 'PriceAmount', $line['price'], 6);
    }

    protected function taxCategory(DOMElement $parent, string $name, array $category, bool $withExemption = true): void
    {
        $el = $this->cac($parent, $name);
        $this->cbc($el, 'ID', $category['id']);
        $this->cbc($el, 'Percent', number_format($category['rate'], 2, '.', ''));
        if ($withExemption && $category['id'] !== 'S') {
            if (empty($category['exemption_code'])) {
                throw ValidationException::withMessages(['zatca' => 'يوجد صنف بنسبة صفر أو معفى أو خارج النطاق دون سبب إعفاء. حدده في بطاقة الصنف أو اضبط السبب الافتراضي في إعدادات الفوترة الإلكترونية.']);
            }
            $this->cbc($el, 'TaxExemptionReasonCode', $category['exemption_code']);
            $this->cbc($el, 'TaxExemptionReason', $category['exemption_reason'] ?? $category['exemption_code']);
        }
        $this->cbc($this->cac($el, 'TaxScheme'), 'ID', 'VAT');
    }

    protected function party(DOMElement $wrapper, array $party, bool $seller): void
    {
        $el = $this->cac($wrapper, 'Party');

        $scheme = $seller ? 'CRN' : ($party['id_scheme'] ?? (! empty($party['crn']) ? 'CRN' : null));
        $identifier = $seller ? ($party['crn'] ?? null) : ($party['id'] ?? $party['crn'] ?? null);
        if ($identifier && $scheme) {
            $this->cbc($this->cac($el, 'PartyIdentification'), 'ID', $identifier, ['schemeID' => $scheme]);
        }

        if (! empty($party['street']) || ! empty($party['city']) || $seller) {
            $address = $this->cac($el, 'PostalAddress');
            foreach (['StreetName' => 'street', 'AdditionalStreetName' => 'additional_street', 'BuildingNumber' => 'building',
                'PlotIdentification' => 'additional_number', 'CitySubdivisionName' => 'district', 'CityName' => 'city',
                'PostalZone' => 'postal_code'] as $tag => $key) {
                if (! empty($party[$key])) {
                    $this->cbc($address, $tag, (string) $party[$key]);
                }
            }
            $this->cbc($this->cac($address, 'Country'), 'IdentificationCode', $party['country'] ?? 'SA');
        }

        if (! empty($party['vat'])) {
            $tax = $this->cac($el, 'PartyTaxScheme');
            $this->cbc($tax, 'CompanyID', $party['vat']);
            $this->cbc($this->cac($tax, 'TaxScheme'), 'ID', 'VAT');
        } elseif ($seller === false && $party) {
            $this->cbc($this->cac($this->cac($el, 'PartyTaxScheme'), 'TaxScheme'), 'ID', 'VAT');
        }

        if (! empty($party['name'])) {
            $this->cbc($this->cac($el, 'PartyLegalEntity'), 'RegistrationName', $party['name']);
        }
    }

    protected function attachment(DOMElement $root, string $id, string $value): void
    {
        $ref = $this->cac($root, 'AdditionalDocumentReference');
        $this->cbc($ref, 'ID', $id);
        $this->cbc($this->cac($ref, 'Attachment'), 'EmbeddedDocumentBinaryObject', $value, ['mimeCode' => 'text/plain']);
    }

    protected function cac(DOMElement $parent, string $name): DOMElement
    {
        return $parent->appendChild($this->doc->createElementNS(self::NS['cac'], 'cac:'.$name));
    }

    protected function cbc(DOMElement $parent, string $name, string $value, array $attributes = []): DOMElement
    {
        $el = $this->doc->createElementNS(self::NS['cbc'], 'cbc:'.$name);
        $el->appendChild($this->doc->createTextNode($value));
        foreach ($attributes as $key => $attr) {
            $el->setAttribute($key, $attr);
        }

        return $parent->appendChild($el);
    }

    protected function amount(DOMElement $parent, string $name, float $value, int $decimals = 2): DOMElement
    {
        $formatted = number_format(round($value, $decimals), $decimals, '.', '');
        if ($decimals > 2) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
            $formatted = str_contains($formatted, '.') && strlen(substr(strrchr($formatted, '.'), 1)) >= 2 ? $formatted : number_format((float) $formatted, 2, '.', '');
        }

        return $this->cbc($parent, $name, $formatted, ['currencyID' => $this->currency]);
    }

    protected function quantity(float $quantity): string
    {
        $formatted = rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');

        return str_contains($formatted, '.') ? $formatted : $formatted.'.00';
    }
}

<?php

namespace App\Services\Zatca;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Turns ERP documents (sales invoice, credit note, debit note) into the
 * neutral data structure UblInvoiceBuilder consumes.
 */
class DocumentMapper
{
    public const STANDARD = '0100000';

    public const SIMPLIFIED = '0200000';

    public function map(Model $document, Company $company): array
    {
        return match (true) {
            $document instanceof Invoice => $this->invoice($document, $company),
            $document instanceof CreditNote => $this->creditNote($document, $company),
            $document instanceof DebitNote => $this->debitNote($document, $company),
            default => throw new InvalidArgumentException('Unsupported e-invoice document: '.$document::class),
        };
    }

    public function seller(Company $company): array
    {
        return [
            'name' => $company->name,
            'vat' => $company->tax_number,
            'crn' => $company->commercial_registration,
            'street' => $company->setting('seller_street'),
            'building' => $company->setting('seller_building_number'),
            'additional_number' => $company->setting('seller_additional_number'),
            'district' => $company->setting('seller_district'),
            'city' => $company->setting('seller_city'),
            'postal_code' => $company->setting('seller_postal_code'),
            'country' => 'SA',
        ];
    }

    /** Fields ZATCA rejects documents without. */
    public function missingSellerFields(Company $company): array
    {
        $seller = $this->seller($company);
        $required = ['name' => 'اسم المنشأة', 'vat' => 'الرقم الضريبي', 'crn' => 'السجل التجاري', 'street' => 'اسم الشارع',
            'building' => 'رقم المبنى', 'district' => 'الحي', 'city' => 'المدينة', 'postal_code' => 'الرمز البريدي'];

        $missing = array_values(array_filter($required, fn ($label, $key) => blank($seller[$key]), ARRAY_FILTER_USE_BOTH));
        if (! blank($seller['vat']) && ! preg_match('/^3\d{13}3$/', (string) $seller['vat'])) {
            $missing[] = 'رقم ضريبي صحيح (15 رقماً يبدأ وينتهي بـ 3)';
        }

        return $missing;
    }

    protected function invoice(Invoice $invoice, Company $company): array
    {
        $invoice->loadMissing('items', 'customer');

        return $this->base($invoice, $company, [
            'number' => $invoice->invoice_number,
            'uuid' => $invoice->zatca_uuid ?: (string) Str::uuid(),
            'date' => $invoice->invoice_date,
            'type_code' => '388',
            'subtype' => $invoice->invoice_type === 'simplified' ? self::SIMPLIFIED : self::STANDARD,
            'supply_date' => $invoice->supply_date ? Carbon::parse($invoice->supply_date)->toDateString() : null,
            'payment_means' => $this->paymentMeans($invoice->payment_method),
            'customer' => $invoice->customer,
            'lines' => $invoice->items->map(fn ($item) => $this->vat([
                'name' => $item->item_name,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount' => (float) $item->discount,
                'tax_rate' => (float) $item->tax_rate,
            ], $item->vat_category, $item->vat_exemption_code, $item->product_id))->all(),
        ]);
    }

    protected function creditNote(CreditNote $note, Company $company): array
    {
        $note->loadMissing('items', 'customer', 'invoice');

        $lines = $note->items->isNotEmpty()
            ? $note->items->map(function ($item) {
                $net = (float) $item->total - (float) $item->tax_amount;

                return $this->vat([
                    'name' => $item->item_name,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->quantity > 0 ? round($net / (float) $item->quantity, 6) : $net,
                    'discount' => 0,
                    'tax_rate' => (float) $item->tax_rate,
                ], $item->vat_category, $item->vat_exemption_code, $item->product_id);
            })->all()
            : [$this->amountLine('تخفيض / تسوية', (float) $note->subtotal, (float) $note->tax_amount)];

        return $this->base($note, $company, [
            'number' => $note->credit_note_number,
            'uuid' => $note->zatca_uuid ?: (string) Str::uuid(),
            'date' => $note->credit_note_date,
            'type_code' => '381',
            'subtype' => $note->invoice?->invoice_type === 'simplified' ? self::SIMPLIFIED : self::STANDARD,
            'billing_reference' => $note->invoice?->invoice_number ?? $note->credit_note_number,
            'instruction_note' => $note->reason,
            'payment_means' => $this->paymentMeans($note->refund_method),
            'customer' => $note->customer,
            'lines' => $lines,
        ]);
    }

    protected function debitNote(DebitNote $note, Company $company): array
    {
        $note->loadMissing('customer', 'invoice');

        return $this->base($note, $company, [
            'number' => $note->debit_note_number,
            'uuid' => $note->zatca_uuid ?: (string) Str::uuid(),
            'date' => $note->debit_note_date,
            'type_code' => '383',
            'subtype' => $note->invoice?->invoice_type === 'simplified' ? self::SIMPLIFIED : self::STANDARD,
            'billing_reference' => $note->invoice?->invoice_number ?? $note->debit_note_number,
            'instruction_note' => $note->reason,
            'payment_means' => '30',
            'customer' => $note->customer,
            'lines' => [$this->vat(['name' => mb_substr($note->reason, 0, 200), 'quantity' => 1, 'unit_price' => (float) $note->subtotal, 'discount' => 0, 'tax_rate' => (float) $note->tax_rate])],
        ]);
    }

    protected function base(Model $document, Company $company, array $data): array
    {
        $issuedAt = $document->created_at ? Carbon::parse($document->created_at)->timezone($company->timezone ?: 'Asia/Riyadh') : now('Asia/Riyadh');

        return [
            'number' => $data['number'],
            'uuid' => $data['uuid'],
            'issue_date' => Carbon::parse($data['date'])->toDateString(),
            'issue_time' => $issuedAt->format('H:i:s'),
            'type_code' => $data['type_code'],
            'subtype' => $data['subtype'],
            // Foreign-currency documents: amounts in their currency, VAT also
            // reported in SAR at the document rate (KSA-15 / BR-KSA-68).
            'currency' => $document->currency_code ?: 'SAR',
            'exchange_rate' => (float) ($document->exchange_rate ?: 1),
            'supply_date' => $data['supply_date'] ?? null,
            'billing_reference' => $data['billing_reference'] ?? null,
            'instruction_note' => $data['instruction_note'] ?? null,
            'payment_means' => $data['payment_means'],
            'seller' => $this->seller($company),
            'buyer' => $this->buyer($data['customer'], $data['subtype']),
            'lines' => $data['lines'],
        ];
    }

    protected function buyer(?Customer $customer, string $subtype): ?array
    {
        if (! $customer || ($subtype === self::SIMPLIFIED && $customer->code === 'WALK-IN')) {
            return null;
        }

        return [
            'name' => $customer->company_name ?: $customer->name,
            'vat' => $customer->tax_number,
            'crn' => $customer->tax_number ? null : $customer->commercial_registration,
            'street' => $customer->street_name ?: $customer->address,
            'building' => $customer->building_number,
            'additional_number' => $customer->additional_details,
            'district' => $customer->district,
            'city' => $customer->city,
            'postal_code' => $customer->postal_code,
            'country' => strlen((string) $customer->country) === 2 ? strtoupper($customer->country) : 'SA',
        ];
    }

    /** One line representing an amount-only adjustment. */
    protected function amountLine(string $name, float $net, float $tax): array
    {
        $rate = $net > 0 ? round($tax / $net * 100) : 15;

        return $this->vat(['name' => $name, 'quantity' => 1, 'unit_price' => $net, 'discount' => 0, 'tax_rate' => in_array($rate, [0.0, 5.0, 15.0], true) ? $rate : 15]);
    }

    /**
     * Attach VAT category, exemption code and reason text to a UBL line.
     */
    protected function vat(array $line, ?string $category = null, ?string $code = null, ?int $productId = null): array
    {
        $classifier = app(VatClassifier::class);
        [$category, $code] = $classifier->classify($productId ? Product::withTrashed()->find($productId) : null, (float) $line['tax_rate'], $category, $code);

        return $line + ['tax_category' => $category, 'exemption_code' => $code, 'exemption_reason' => $classifier->reasonText($category, $code)];
    }

    /** UNTDID 4461 payment means. */
    protected function paymentMeans(?string $method): string
    {
        return match ($method) {
            'cash' => '10',
            'card', 'mada' => '48',
            'bank_transfer' => '42',
            'cheque' => '20',
            default => '30',
        };
    }
}

<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PrintTemplate;
use App\Models\ProgressBilling;
use App\Models\Quotation;
use App\Models\ZatcaDocument;
use App\Services\Sales\CustomerStatement;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Support\CurrencyNames;
use App\Support\Hijri;
use App\Support\Tafqeet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Builds the official PDF of a customer-facing document. Every document type
 * is mapped onto one layout (resources/views/pdf/document.blade.php) styled
 * by the company's default print template, so the PDF follows the designer.
 */
class DocumentPdf
{
    public function __construct(protected PdfRenderer $renderer) {}

    /** @return array{content: string, filename: string} */
    public function make(Model $document, ?int $templateId = null): array
    {
        // Free-form designs (and kinds that only print free-form) take over.
        $freeform = app(FreeformTemplates::class);
        if ($layout = $freeform->resolve($document, $templateId)) {
            $number = app(DocumentSharing::class)->number($document);
            $attachments = [];
            if ($xml = $this->signedXml($document)) {
                $attachments[] = ['name' => $number.'.xml', 'mime' => 'text/xml', 'description' => 'ZATCA signed e-invoice (UBL 2.1)', 'content' => $xml];
            }

            return [
                'content' => $freeform->render($document, $layout, $attachments, TemplateData::KINDS[TemplateData::kindOf($document)][0].' '.$number),
                'filename' => $this->filename($number),
            ];
        }

        $data = match (true) {
            $document instanceof Invoice => $this->invoice($document),
            $document instanceof CreditNote => $this->creditNote($document),
            $document instanceof Quotation => $this->quotation($document),
            $document instanceof ProgressBilling => $this->progressBilling($document),
            default => throw new InvalidArgumentException('Unsupported document '.$document::class),
        };

        $data += $this->common($document->company_id);
        $data += $this->currency($document);
        $data['meta'] = $this->withHijri($data['meta'], $document->company_id);
        $attachments = [];
        if ($xml = $this->signedXml($document)) {
            $attachments[] = ['name' => $data['number'].'.xml', 'mime' => 'text/xml', 'description' => 'ZATCA signed e-invoice (UBL 2.1)', 'content' => $xml];
        }

        return [
            'content' => $this->renderer->render('pdf.document', $data, $attachments, $data['title'].' '.$data['number']),
            'filename' => $this->filename($data['number']),
        ];
    }

    /** @return array{content: string, filename: string} */
    public function statement(Customer $customer, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $statement = app(CustomerStatement::class)->build($customer, $from, $to);
        $data = $this->common($customer->company_id) + [
            'title' => 'كشف حساب عميل',
            'customer' => $this->party($customer),
            'from' => $from?->toDateString(),
            'to' => ($to ?? now())->toDateString(),
        ] + $statement;

        return [
            'content' => $this->renderer->render('pdf.statement', $data, [], 'كشف حساب '.$customer->name),
            'filename' => $this->filename('statement-'.($customer->code ?: $customer->id)),
        ];
    }

    protected function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'customer']);
        $simplified = $invoice->invoice_type === 'simplified';
        [$title, $titleEn] = TaxProfile::forDocument($invoice)->invoiceTitle($simplified);

        return [
            'title' => $title,
            'title_en' => $titleEn,
            'number' => $invoice->invoice_number,
            'meta' => array_filter([
                'رقم الفاتورة' => $invoice->invoice_number,
                'تاريخ الإصدار' => $invoice->invoice_date?->format('Y-m-d'),
                'تاريخ التوريد' => $invoice->supply_date?->format('Y-m-d'),
                'تاريخ الاستحقاق' => $invoice->due_date?->format('Y-m-d'),
                'الرقم المرجعي' => $invoice->reference_number,
            ]),
            'customer' => $this->party($invoice->customer),
            'lines' => $invoice->items->map(fn ($i) => $this->line($i->item_name, $i->description, $i->quantity, $i->unit_price, $i->discount, $i->tax_rate, $i->tax_amount, $i->total))->all(),
            'totals' => $this->totals($invoice->subtotal, $invoice->discount_amount, $invoice->tax_amount, $invoice->total, $invoice->paid_amount, $invoice->remaining_amount),
            'qr' => $invoice->zatca_qr,
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
        ];
    }

    protected function creditNote(CreditNote $note): array
    {
        $note->loadMissing(['items', 'customer', 'invoice:id,invoice_number']);

        return [
            'title' => 'إشعار دائن',
            'title_en' => 'Credit Note',
            'number' => $note->credit_note_number,
            'meta' => array_filter([
                'رقم الإشعار' => $note->credit_note_number,
                'التاريخ' => $note->credit_note_date?->format('Y-m-d'),
                'الفاتورة الأصلية' => $note->invoice?->invoice_number,
                'السبب' => $note->reason,
            ]),
            'customer' => $this->party($note->customer),
            'lines' => $note->items->map(fn ($i) => $this->line($i->item_name, null, $i->quantity, $i->unit_price, 0, $i->tax_rate, $i->tax_amount, $i->total))->all(),
            'totals' => $this->totals($note->subtotal, 0, $note->tax_amount, $note->total),
            'qr' => $note->zatca_qr,
            'notes' => null,
            'terms' => null,
        ];
    }

    protected function quotation(Quotation $quotation): array
    {
        $quotation->loadMissing(['items', 'customer']);

        return [
            'title' => 'عرض سعر',
            'title_en' => 'Quotation',
            'number' => $quotation->quotation_number,
            'meta' => array_filter([
                'رقم العرض' => $quotation->quotation_number,
                'التاريخ' => $quotation->quotation_date?->format('Y-m-d'),
                'صالح حتى' => $quotation->valid_until?->format('Y-m-d'),
            ]),
            'customer' => $this->party($quotation->customer),
            'lines' => $quotation->items->map(fn ($i) => $this->line($i->item_name, $i->description, $i->quantity, $i->unit_price, $i->discount, $i->tax_rate, $i->tax_amount, $i->total))->all(),
            'totals' => $this->totals($quotation->subtotal, $quotation->discount_amount, $quotation->tax_amount, $quotation->total),
            'qr' => null,
            'notes' => $quotation->notes,
            'terms' => $quotation->terms,
        ];
    }

    protected function progressBilling(ProgressBilling $billing): array
    {
        $billing->loadMissing(['items.boqItem', 'project.customer', 'invoice:id,invoice_number,zatca_qr']);
        $rate = $billing->taxable_amount > 0 ? round($billing->vat_amount / $billing->taxable_amount * 100, 2) : 15;

        return [
            'title' => 'مستخلص أعمال',
            'title_en' => 'Progress Billing',
            'number' => $billing->number,
            'meta' => array_filter([
                'رقم المستخلص' => $billing->number.' (#'.$billing->sequence.')',
                'المشروع' => $billing->project?->name,
                'تاريخ المستخلص' => $billing->billing_date?->format('Y-m-d'),
                'الفترة' => $billing->period_from && $billing->period_to ? $billing->period_from->format('Y-m-d').' — '.$billing->period_to->format('Y-m-d') : null,
                'الفاتورة' => $billing->invoice?->invoice_number,
            ]),
            'customer' => $this->party($billing->project?->customer),
            'lines' => $billing->items->map(fn ($i) => [
                'name' => trim(($i->boqItem?->code ? $i->boqItem->code.' — ' : '').($i->boqItem?->description ?? '')),
                'description' => 'السابق '.$this->qty($i->previous_quantity).' · الحالي '.$this->qty($i->current_quantity).' '.($i->boqItem?->unit ?? ''),
                'quantity' => $this->qty($i->current_quantity),
                'unit_price' => (float) $i->unit_price,
                'discount' => 0,
                'tax_rate' => $rate,
                'tax_amount' => round((float) $i->amount * $rate / 100, 2),
                'total' => (float) $i->amount,
            ])->all(),
            'totals' => array_values(array_filter([
                ['قيمة الأعمال حتى تاريخه', (float) $billing->cumulative_value],
                ['المستخلصات السابقة', -(float) $billing->previous_value],
                ['قيمة المستخلص الحالي', (float) $billing->current_value],
                $billing->advance_recovery > 0 ? ['استرداد الدفعة المقدمة', -(float) $billing->advance_recovery] : null,
                $billing->retention > 0 ? ['المحتجزات', -(float) $billing->retention] : null,
                ['المبلغ الخاضع للضريبة', (float) $billing->taxable_amount],
                ['ضريبة القيمة المضافة', (float) $billing->vat_amount],
                ['صافي المستحق', (float) $billing->net_payable, true],
            ])),
            'grand_total' => (float) $billing->net_payable,
            'qr' => $billing->invoice?->zatca_qr,
            'notes' => $billing->notes,
            'terms' => null,
        ];
    }

    /** Adds the Hijri date after the document's first date (company setting, on by default). */
    protected function withHijri(array $meta, int $companyId): array
    {
        $company = Company::find($companyId);
        if ($company && in_array($company->setting('show_hijri', '1'), ['0', false, 0], true)) {
            return $meta;
        }
        $out = [];
        $added = false;
        foreach ($meta as $label => $value) {
            $out[$label] = $value;
            if (! $added && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $out['التاريخ الهجري'] = Hijri::format($value);
                $added = true;
            }
        }

        return $out;
    }

    /** Currency shown on the document; one other than the base also gives the base equivalent. */
    protected function currency(Model $document): array
    {
        $base = strtoupper((string) (Company::find($document->company_id)?->currency ?: 'SAR'));
        $code = strtoupper((string) ($document->currency_code ?: $base));
        [$name, $fraction, $symbol] = CurrencyNames::for($code);
        if ($code === $base) {
            return ['currency_label' => $symbol, 'currency_words' => [$name, $fraction], 'base_equivalent' => null, 'base_currency' => $base];
        }
        $rate = (float) ($document->exchange_rate ?: 1);
        $total = (float) ($document->total ?? $document->net_payable ?? 0);

        return [
            'currency_label' => $code,
            'currency_words' => [$name, $fraction],
            'base_equivalent' => ['rate' => $rate, 'total' => round($total * $rate, 2), 'tax' => round((float) ($document->tax_amount ?? 0) * $rate, 2)],
            'base_currency' => $base,
        ];
    }

    protected function common(int $companyId): array
    {
        $company = app(TenantManager::class)->getCompany()?->id === $companyId
            ? app(TenantManager::class)->getCompany()
            : Company::findOrFail($companyId);
        $template = PrintTemplate::where('company_id', $companyId)->where('template_type', 'invoice_a4')->orderByDesc('is_default')->first();
        $layout = array_replace_recursive(PrintTemplate::getDefaultLayoutData(), $template?->layout_data ?? []);
        $logoPath = $company->logoPath();

        return [
            'company' => [
                'name' => $company->name,
                'tax_number' => $company->tax_number,
                'cr' => $company->commercial_registration,
                'phone' => $company->phone,
                'email' => $company->email,
                'address' => collect([$company->setting('seller_building_number'), $company->setting('seller_street'), $company->setting('seller_district'), $company->setting('seller_city'), $company->setting('seller_postal_code')])->filter()->implode('، '),
                'currency' => CurrencyNames::for($company->currency)[0],
            ],
            'logo' => ($layout['logo']['show'] ?? true) && $logoPath ? Storage::disk('public')->path($logoPath) : null,
            'layout' => $layout,
            'color' => $layout['design']['primary_color'] ?? $template?->primary_color ?? '#1e293b',
            'footer_text' => $template?->footer_text,
            'template_terms' => $template?->terms_and_conditions,
        ];
    }

    protected function party(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        return [
            'name' => $customer->company_name ?: $customer->name,
            'contact' => $customer->company_name ? $customer->name : null,
            'tax_number' => $customer->tax_number,
            'cr' => $customer->commercial_registration,
            'phone' => $customer->mobile ?: $customer->phone,
            'address' => collect([$customer->building_number, $customer->street_name, $customer->district, $customer->city, $customer->postal_code])->filter()->implode('، ') ?: $customer->address,
        ];
    }

    protected function line(?string $name, ?string $description, $qty, $price, $discount, $taxRate, $tax, $total): array
    {
        return [
            'name' => (string) $name,
            'description' => $description,
            'quantity' => $this->qty($qty),
            'unit_price' => (float) $price,
            'discount' => (float) $discount,
            'tax_rate' => (float) $taxRate,
            'tax_amount' => (float) $tax,
            'total' => (float) $total,
        ];
    }

    /** Label/amount rows; the third element marks the grand total. */
    protected function totals($subtotal, $discount, $tax, $total, $paid = null, $due = null): array
    {
        return array_values(array_filter([
            ['الإجمالي غير شامل الضريبة', (float) $subtotal],
            (float) $discount > 0 ? ['الخصم', -(float) $discount] : null,
            ['ضريبة القيمة المضافة', (float) $tax],
            ['الإجمالي شامل الضريبة', (float) $total, true],
            $paid !== null && (float) $paid > 0 ? ['المدفوع', (float) $paid] : null,
            $due !== null && (float) $paid > 0 ? ['المتبقي', (float) $due] : null,
        ]));
    }

    protected function signedXml(Model $document): ?string
    {
        if (! $document instanceof Invoice && ! $document instanceof CreditNote) {
            return null;
        }
        $zatca = ZatcaDocument::where('documentable_type', $document->getMorphClass())->where('documentable_id', $document->id)->latest('id')->first();

        return $zatca?->cleared_xml ?: $zatca?->signed_xml;
    }

    protected function qty($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    protected function filename(string $number): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $number).'.pdf';
    }

    public static function words(float $amount, ?array $currency = null): string
    {
        $currency ??= CurrencyNames::for(app(TenantManager::class)->getCompany()?->currency);

        return Tafqeet::amount($amount, $currency[0], $currency[1]);
    }
}

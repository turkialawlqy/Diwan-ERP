<?php

namespace App\Services\Documents;

use App\Jobs\SendDocumentEmail;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DebitNote;
use App\Models\DocumentDispatch;
use App\Models\Invoice;
use App\Models\ProgressBilling;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\SupplierPayment;
use App\Support\CurrencyNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Sending documents to customers: by email (PDF attached) or WhatsApp (a
 * signed, expiring link). Each type declares its model, the permission that
 * may share it, and how to read its number, total and customer.
 */
class DocumentSharing
{
    public const TYPES = [
        'invoice' => ['model' => Invoice::class, 'permission' => 'sales.invoices.print', 'label' => 'الفاتورة', 'number' => 'invoice_number', 'total' => 'total'],
        'credit_note' => ['model' => CreditNote::class, 'permission' => 'sales.returns.print', 'label' => 'الإشعار الدائن', 'number' => 'credit_note_number', 'total' => 'total'],
        'quotation' => ['model' => Quotation::class, 'permission' => 'sales.quotations.print', 'label' => 'عرض السعر', 'number' => 'quotation_number', 'total' => 'total'],
        'sales_order' => ['model' => SalesOrder::class, 'permission' => 'sales.orders.print', 'label' => 'أمر البيع', 'number' => 'order_number', 'total' => 'total'],
        'debit_note' => ['model' => DebitNote::class, 'permission' => 'sales.debit_notes.print', 'label' => 'الإشعار المدين', 'number' => 'debit_note_number', 'total' => 'total'],
        'receipt' => ['model' => CustomerPayment::class, 'permission' => 'sales.payments.print', 'label' => 'سند القبض', 'number' => 'payment_number', 'total' => 'amount'],
        'payment' => ['model' => SupplierPayment::class, 'permission' => 'purchases.payments.print', 'label' => 'سند الصرف', 'number' => 'payment_number', 'total' => 'amount'],
        'billing' => ['model' => ProgressBilling::class, 'permission' => 'projects.billings.print', 'label' => 'المستخلص', 'number' => 'number', 'total' => 'net_payable'],
        'statement' => ['model' => Customer::class, 'permission' => 'sales.clients.view', 'label' => 'كشف الحساب', 'number' => 'code', 'total' => 'current_balance'],
    ];

    public const DEFAULT_MESSAGE = "السلام عليكم {customer}،\n\nنرفق لكم {document} رقم {number} بمبلغ {total} {currency}.\n\nمع خالص التحية،\n{company}";

    public function find(string $type, int $id): Model
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type]['model']::findOrFail($id);
    }

    public function typeOf(Model $document): string
    {
        foreach (self::TYPES as $type => $def) {
            if ($document instanceof $def['model']) {
                return $type;
            }
        }
        throw new \InvalidArgumentException('Unsupported document.');
    }

    public function customer(Model $document): ?Customer
    {
        return match (true) {
            $document instanceof Customer => $document,
            $document instanceof ProgressBilling => $document->project?->customer,
            $document instanceof SupplierPayment => null,
            default => $document->customer,
        };
    }

    public function number(Model $document): string
    {
        $def = self::TYPES[$this->typeOf($document)];

        return (string) ($document->{$def['number']} ?: $document->getKey());
    }

    /** @return array{content: string, filename: string} */
    public function pdf(Model $document, ?int $templateId = null): array
    {
        return $document instanceof Customer
            ? app(DocumentPdf::class)->statement($document)
            : app(DocumentPdf::class)->make($document, $templateId);
    }

    /** Signed, expiring link to the document's PDF (no login needed). */
    public function pdfLink(Model $document): string
    {
        return URL::temporarySignedRoute('share.pdf', now()->addDays(config('documents.share_link_days')), [
            'company' => $document->company_id, 'type' => $this->typeOf($document), 'id' => $document->getKey(),
        ]);
    }

    /** Signed, expiring link to the customer's statement page. */
    public function portalLink(Customer $customer, bool $pdf = false): string
    {
        return URL::temporarySignedRoute('share.statement', now()->addDays(config('documents.share_link_days')), array_filter([
            'company' => $customer->company_id, 'customer' => $customer->id, 'pdf' => $pdf ? 1 : null,
        ]));
    }

    public function message(Model $document, ?string $template = null): string
    {
        $company = Company::find($document->company_id);
        $template = $template ?: ($company?->setting('document_message') ?: self::DEFAULT_MESSAGE);
        $def = self::TYPES[$this->typeOf($document)];

        return strtr($template, [
            '{customer}' => $this->customer($document)?->name ?? ($document instanceof SupplierPayment ? $document->supplier?->name : null) ?? 'عميلنا العزيز',
            '{document}' => $def['label'],
            '{number}' => $this->number($document),
            '{total}' => number_format((float) $document->{$def['total']}, 2),
            '{currency}' => $document->currency_code ?: CurrencyNames::for(Company::find($document->company_id)?->currency)[2],
            '{company}' => $company?->name ?? '',
        ]);
    }

    public function email(Model $document, string $to, ?string $cc, ?string $message, ?int $userId): DocumentDispatch
    {
        $dispatch = DocumentDispatch::create([
            'company_id' => $document->company_id,
            'documentable_type' => $this->typeOf($document),
            'documentable_id' => $document->getKey(),
            'channel' => 'email',
            'recipient' => $to,
            'cc' => $cc,
            'message' => $message ?: $this->message($document),
            'status' => 'queued',
            'user_id' => $userId,
        ]);

        SendDocumentEmail::dispatch($dispatch->id);

        return $dispatch;
    }

    /** Logs a WhatsApp share and returns the wa.me URL to open. */
    public function whatsapp(Model $document, ?string $phone, ?string $message, ?int $userId): array
    {
        $link = $this->pdfLink($document);
        $text = ($message ?: $this->message($document))."\n\n".$link;
        $customer = $this->customer($document);
        if ($customer && ! $document instanceof Customer) {
            $text .= "\n\nكشف حسابكم: ".$this->portalLink($customer);
        }

        $dispatch = DocumentDispatch::create([
            'company_id' => $document->company_id,
            'documentable_type' => $this->typeOf($document),
            'documentable_id' => $document->getKey(),
            'channel' => 'whatsapp',
            'recipient' => $phone,
            'message' => $text,
            'status' => 'sent',
            'sent_at' => now(),
            'user_id' => $userId,
        ]);

        return ['dispatch' => $dispatch, 'url' => 'https://wa.me/'.$this->waNumber($phone).'?text='.rawurlencode($text)];
    }

    /** Saudi numbers in international form (05XXXXXXXX -> 9665XXXXXXXX). */
    public function waNumber(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', strtr((string) $phone, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '05') && strlen($digits) === 10) {
            $digits = '966'.substr($digits, 1);
        }

        return $digits;
    }
}

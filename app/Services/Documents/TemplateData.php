<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Tax\TaxProfile;
use App\Support\CurrencyNames;
use App\Support\Hijri;
use App\Support\Tafqeet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The values a free-form print template can place anywhere on the page:
 * a flat map of fields ({{party.name}}, {{totals.words}}, ...) plus the item
 * rows of the document. One builder per document kind, one catalogue of
 * Arabic labels for the designer, and sample values for designing before a
 * real document exists.
 */
class TemplateData
{
    /** kind => [Arabic label, model, has items] */
    public const KINDS = [
        'invoice' => ['فاتورة مبيعات', Invoice::class, true],
        'quotation' => ['عرض سعر', Quotation::class, true],
        'sales_order' => ['أمر بيع', SalesOrder::class, true],
        'credit_note' => ['إشعار دائن / مرتجع', CreditNote::class, true],
        'debit_note' => ['إشعار مدين', DebitNote::class, true],
        'receipt' => ['سند قبض', CustomerPayment::class, false],
        'payment' => ['سند صرف لمورد', SupplierPayment::class, false],
    ];

    public const METHODS = ['cash' => 'نقداً', 'credit' => 'آجل', 'bank_transfer' => 'تحويل بنكي', 'card' => 'بطاقة', 'mada' => 'مدى', 'cheque' => 'شيك',
        'check' => 'شيك', 'online' => 'دفع إلكتروني', 'mixed' => 'متعدد'];

    public static function kindOf(Model $document): string
    {
        foreach (self::KINDS as $kind => [, $class]) {
            if ($document instanceof $class) {
                return $kind;
            }
        }
        throw new InvalidArgumentException('No template kind for '.$document::class);
    }

    /** @return array{fields: array<string,string>, items: array<int,array<string,string>>, qr: ?string} */
    public function build(Model $document): array
    {
        $company = Company::findOrFail($document->company_id);
        $data = match (self::kindOf($document)) {
            'invoice' => $this->invoice($document),
            'quotation' => $this->quotation($document),
            'sales_order' => $this->salesOrder($document),
            'credit_note' => $this->creditNote($document),
            'debit_note' => $this->debitNote($document),
            'receipt' => $this->receipt($document),
            'payment' => $this->supplierPayment($document),
        };

        $currency = $this->currency($document);
        $fields = $this->company($company) + $data['fields'];
        $fields['doc.hijri_date'] = ! empty($data['date']) ? Hijri::format($data['date']) : '';
        $fields['totals.currency'] = $currency[2];
        $fields['totals.words'] = isset($data['amount']) ? Tafqeet::amount((float) $data['amount'], $currency[0], $currency[1]) : '';

        return ['fields' => array_map(fn ($v) => $v === null ? '' : (string) $v, $fields), 'items' => $data['items'] ?? [], 'qr' => $data['qr'] ?? null];
    }

    /** Designer catalogue: groups of [key, label], plus item columns. */
    public static function catalogue(string $kind): array
    {
        $voucher = in_array($kind, ['receipt', 'payment'], true);
        $party = $kind === 'payment' ? 'المورد' : 'العميل';

        $groups = [
            'company' => ['الشركة', [
                ['company.name', 'اسم الشركة'], ['company.vat', 'الرقم الضريبي'], ['company.cr', 'السجل التجاري'], ['company.phone', 'الهاتف'],
                ['company.email', 'البريد'], ['company.address', 'العنوان الكامل'], ['company.building_number', 'رقم المبنى'], ['company.street', 'الشارع'],
                ['company.district', 'الحي'], ['company.city', 'المدينة'], ['company.postal_code', 'الرمز البريدي'], ['company.additional_number', 'الرقم الإضافي'],
                ['doc.branch', 'الفرع'],
            ]],
            'doc' => ['المستند', array_values(array_filter([
                ['doc.title', 'عنوان المستند'], ['doc.title_en', 'العنوان بالإنجليزي'], ['doc.number', 'الرقم'], ['doc.date', 'التاريخ'],
                ['doc.hijri_date', 'التاريخ الهجري'], ['doc.time', 'الوقت'],
                in_array($kind, ['invoice'], true) ? ['doc.supply_date', 'تاريخ التوريد'] : null,
                in_array($kind, ['invoice'], true) ? ['doc.due_date', 'تاريخ الاستحقاق'] : null,
                $kind === 'quotation' ? ['doc.valid_until', 'صالح حتى'] : null,
                $kind === 'sales_order' ? ['doc.delivery_date', 'تاريخ التسليم'] : null,
                in_array($kind, ['credit_note', 'debit_note'], true) ? ['doc.original_number', 'الفاتورة الأصلية'] : null,
                in_array($kind, ['credit_note', 'debit_note'], true) ? ['doc.reason', 'السبب'] : null,
                ['doc.reference', 'الرقم المرجعي'],
                in_array($kind, ['invoice', 'receipt', 'payment'], true) ? ['doc.payment_method', 'طريقة الدفع'] : null,
                $kind === 'invoice' ? ['doc.payment_type', 'نقدي / آجل'] : null,
                $voucher ? ['doc.bank', 'البنك / الصندوق'] : null,
                $voucher ? ['doc.for', 'وذلك عن (الفاتورة)'] : null,
                ['doc.salesperson', 'البائع / المستخدم'], ['doc.notes', 'ملاحظات'],
                $voucher ? null : ['doc.terms', 'الشروط والأحكام'],
                ['page.number', 'رقم الصفحة'], ['page.total', 'عدد الصفحات'],
            ]))],
            'party' => [$party, array_values(array_filter([
                ['party.name', 'الاسم'], ['party.code', 'الرقم / الكود'], ['party.vat', 'الرقم الضريبي'], ['party.cr', 'السجل التجاري'],
                ['party.phone', 'الجوال'], ['party.email', 'البريد'], ['party.address', 'العنوان الكامل'],
                $kind === 'payment' ? null : ['party.national_address', 'العنوان الوطني (منسّق)'],
                $kind === 'payment' ? null : ['party.building_number', 'رقم المبنى'],
                $kind === 'payment' ? null : ['party.street', 'الشارع'],
                $kind === 'payment' ? null : ['party.district', 'الحي'],
                $kind === 'payment' ? null : ['party.city', 'المدينة'],
                $kind === 'payment' ? null : ['party.postal_code', 'الرمز البريدي'],
                $kind === 'payment' ? null : ['party.additional_number', 'الرقم الإضافي'],
                $kind === 'payment' ? null : ['party.country', 'الدولة'],
            ]))],
            'totals' => [$voucher ? 'المبلغ' : 'الإجماليات', $voucher
                ? [['totals.total', 'المبلغ'], ['totals.words', 'المبلغ كتابةً (تفقيط)'], ['totals.currency', 'العملة']]
                : [['totals.gross', 'المجموع قبل الخصم'], ['totals.discount', 'الخصم'], ['totals.net', 'الإجمالي بعد الخصم'], ['totals.vat_rate', 'نسبة الضريبة'],
                    ['totals.vat', 'ضريبة القيمة المضافة'], ['totals.total', 'الإجمالي شامل الضريبة'], ['totals.paid', 'المدفوع'], ['totals.remaining', 'المتبقي'],
                    ['totals.words', 'الإجمالي كتابةً (تفقيط)'], ['totals.currency', 'العملة'], ['totals.items_count', 'عدد البنود'], ['totals.quantity', 'مجموع الكميات']]],
        ];

        return [
            'kind' => $kind,
            'label' => self::KINDS[$kind][0],
            'has_items' => self::KINDS[$kind][2],
            'groups' => collect($groups)->map(fn ($g, $key) => ['key' => $key, 'label' => $g[0], 'fields' => array_map(fn ($f) => ['key' => $f[0], 'label' => $f[1]], $g[1])])->values()->all(),
            'columns' => self::KINDS[$kind][2] ? self::columns() : [],
        ];
    }

    /** Item columns: key => [Arabic header, English header, default align]. */
    public static function columns(): array
    {
        return collect([
            'index' => ['م', 'S#', 'center'], 'sku' => ['رقم الصنف', 'Item No.', 'center'], 'name' => ['الصنف', 'Item', 'right'],
            'description' => ['الوصف', 'Description', 'right'], 'name_description' => ['الصنف والوصف', 'Description', 'right'],
            'unit' => ['الوحدة', 'Unit', 'center'], 'quantity' => ['الكمية', 'Qty.', 'center'], 'unit_price' => ['السعر', 'U. Price', 'center'],
            'gross' => ['المجموع', 'Total', 'center'], 'discount' => ['الخصم', 'Discount', 'center'], 'net' => ['بعد الخصم', 'Net', 'center'],
            'tax_rate' => ['%', 'VAT %', 'center'], 'tax_amount' => ['الضريبة', 'VAT', 'center'], 'total' => ['الإجمالي', 'Amount', 'center'],
        ])->map(fn ($c, $key) => ['key' => $key, 'label' => $c[0], 'label_en' => $c[1], 'align' => $c[2]])->values()->all();
    }

    /** Plausible values for designing before (or without) a real document. */
    public function sample(string $kind, Company $company): array
    {
        $voucher = in_array($kind, ['receipt', 'payment'], true);
        $items = $voucher ? [] : [
            $this->row(1, 'PRD-00046', 'أعمال مشاريع', 'تنظيف الخزانات بالمراكز الصحية', 'عمل', 5, 450, 0, 15),
            $this->row(2, 'PRD-00012', 'صيانة دورية', 'صيانة مكيفات المبنى الرئيسي', 'زيارة', 2, 300, 50, 15),
        ];
        $gross = $voucher ? 0 : collect($items)->sum(fn ($r) => (float) str_replace(',', '', $r['gross']));
        $total = $voucher ? 2587.50 : 3122.50;
        $titles = [
            'invoice' => ['فاتورة ضريبية', 'Tax Invoice'], 'quotation' => ['عرض سعر', 'Quotation'], 'sales_order' => ['أمر بيع', 'Sales Order'],
            'credit_note' => ['إشعار دائن', 'Credit Note'], 'debit_note' => ['إشعار مدين', 'Debit Note'], 'receipt' => ['سند قبض', 'Receipt Voucher'],
            'payment' => ['سند صرف', 'Payment Voucher'],
        ];

        $fields = $this->company($company) + [
            'doc.title' => $titles[$kind][0], 'doc.title_en' => $titles[$kind][1], 'doc.number' => 'INV-26-000023', 'doc.date' => now()->format('Y/m/d'),
            'doc.hijri_date' => Hijri::format(now()->toDateString()), 'doc.time' => '08:02', 'doc.supply_date' => now()->format('Y/m/d'),
            'doc.due_date' => now()->addDays(30)->format('Y/m/d'), 'doc.valid_until' => now()->addDays(15)->format('Y/m/d'), 'doc.delivery_date' => now()->addDays(7)->format('Y/m/d'),
            'doc.original_number' => 'INV-26-000019', 'doc.reason' => 'مرتجع جزئي', 'doc.reference' => 'PO-7781', 'doc.payment_method' => 'تحويل بنكي',
            'doc.payment_type' => 'آجل', 'doc.bank' => 'مصرف الراجحي', 'doc.for' => 'سداد الفاتورة INV-26-000019', 'doc.salesperson' => 'أحمد',
            'doc.notes' => 'شكراً لتعاملكم معنا', 'doc.terms' => 'تعتبر هذه الفاتورة ضريبية رسمية.', 'doc.branch' => 'الفرع الرئيسي',
            'party.name' => $kind === 'payment' ? 'مؤسسة التوريدات المتحدة' : 'شركة مجموعة المجال العربي', 'party.code' => '5', 'party.vat' => '300231461500003',
            'party.cr' => '1010101010', 'party.phone' => '0500000000', 'party.email' => 'info@example.com',
            'party.address' => '2851، الملك عبدالله بن عبدالعزيز، الملك سلمان، الرياض 12444', 'party.building_number' => '2851',
            'party.national_address' => 'مبنى 2851، الملك عبدالله بن عبدالعزيز، حي الملك سلمان، الرياض 12444، الرقم الإضافي 8961',
            'party.street' => 'الملك عبدالله بن عبدالعزيز سعود الفرعي', 'party.district' => 'الملك سلمان', 'party.city' => 'الرياض', 'party.postal_code' => '12444',
            'party.additional_number' => '8961', 'party.country' => 'السعودية',
            'totals.gross' => $this->money($gross), 'totals.discount' => $this->money(50), 'totals.net' => $this->money($gross - 50), 'totals.vat_rate' => '15%',
            'totals.vat' => $this->money(round(($gross - 50) * 0.15, 2)), 'totals.total' => $this->money($total), 'totals.paid' => $this->money(0),
            'totals.remaining' => $this->money($total), 'totals.words' => Tafqeet::amount($total), 'totals.currency' => 'ر.س',
            'totals.items_count' => (string) count($items), 'totals.quantity' => '7',
        ];

        return ['fields' => $fields, 'items' => $items, 'qr' => 'AQ1TYW1wbGUgU2VsbGVyAg8zMDAwMDAwMDAwMDAwMDM='];
    }

    // ------------------------------------------------------------ builders

    protected function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['items.product.unit', 'customer', 'branch', 'user', 'bankAccount']);
        $simplified = $invoice->invoice_type === 'simplified';
        $titles = TaxProfile::forDocument($invoice)->invoiceTitle($simplified);

        return [
            'date' => $invoice->invoice_date?->toDateString(),
            'amount' => $invoice->total,
            'qr' => $invoice->zatca_qr,
            'items' => $this->items($invoice->items),
            'fields' => $this->doc($titles[0], $titles[1], $invoice->invoice_number,
                $invoice->invoice_date ?? $invoice->created_at, $invoice->created_at) + [
                    'doc.supply_date' => $this->date($invoice->supply_date), 'doc.due_date' => $this->date($invoice->due_date),
                    'doc.reference' => $invoice->reference_number, 'doc.payment_method' => self::METHODS[$invoice->payment_method] ?? $invoice->payment_method,
                    'doc.payment_type' => in_array($invoice->payment_method, ['credit'], true) || (float) $invoice->remaining_amount > 0 ? 'آجل' : 'نقدي',
                    'doc.salesperson' => $invoice->user?->name, 'doc.notes' => $invoice->notes, 'doc.terms' => $invoice->terms, 'doc.branch' => $invoice->branch?->name_ar,
                ] + $this->customer($invoice->customer) + $this->totals($invoice->items, $invoice->discount_amount, $invoice->tax_amount, $invoice->total, $invoice->paid_amount, $invoice->remaining_amount),
        ];
    }

    protected function quotation(Quotation $quotation): array
    {
        $quotation->loadMissing(['items.product.unit', 'customer']);

        return [
            'date' => $quotation->quotation_date?->toDateString(),
            'amount' => $quotation->total,
            'items' => $this->items($quotation->items),
            'fields' => $this->doc('عرض سعر', 'Quotation', $quotation->quotation_number, $quotation->quotation_date, $quotation->created_at) + [
                'doc.valid_until' => $this->date($quotation->valid_until), 'doc.notes' => $quotation->notes, 'doc.terms' => $quotation->terms,
                'doc.salesperson' => User::find($quotation->user_id)?->name,
            ] + $this->customer($quotation->customer) + $this->totals($quotation->items, $quotation->discount_amount, $quotation->tax_amount, $quotation->total),
        ];
    }

    protected function salesOrder(SalesOrder $order): array
    {
        $order->loadMissing(['items.product.unit', 'customer', 'salesperson', 'branch']);

        return [
            'date' => $order->order_date?->toDateString(),
            'amount' => $order->total,
            'items' => $this->items($order->items),
            'fields' => $this->doc('أمر بيع', 'Sales Order', $order->order_number, $order->order_date, $order->created_at) + [
                'doc.delivery_date' => $this->date($order->delivery_date), 'doc.notes' => $order->notes, 'doc.salesperson' => $order->salesperson?->name,
                'doc.branch' => $order->branch?->name_ar,
            ] + $this->customer($order->customer) + $this->totals($order->items, $order->discount_amount, $order->tax_amount, $order->total),
        ];
    }

    protected function creditNote(CreditNote $note): array
    {
        $note->loadMissing(['items.product.unit', 'customer', 'invoice:id,invoice_number']);

        return [
            'date' => $note->credit_note_date?->toDateString(),
            'amount' => $note->total,
            'qr' => $note->zatca_qr,
            'items' => $this->items($note->items),
            'fields' => $this->doc('إشعار دائن', 'Credit Note', $note->credit_note_number, $note->credit_note_date, $note->created_at) + [
                'doc.original_number' => $note->invoice?->invoice_number, 'doc.reason' => $note->reason,
            ] + $this->customer($note->customer) + $this->totals($note->items, 0, $note->tax_amount, $note->total),
        ];
    }

    protected function debitNote(DebitNote $note): array
    {
        $note->loadMissing(['customer', 'invoice:id,invoice_number']);
        $rate = (float) $note->tax_rate ?: ((float) $note->subtotal > 0 ? round($note->tax_amount / $note->subtotal * 100, 2) : 15);
        $row = $this->row(1, '', $note->reason ?: 'إشعار مدين', null, '', 1, (float) $note->subtotal, 0, $rate);

        return [
            'date' => $note->debit_note_date?->toDateString(),
            'amount' => $note->total,
            'qr' => $note->zatca_qr,
            'items' => [$row],
            'fields' => $this->doc('إشعار مدين', 'Debit Note', $note->debit_note_number, $note->debit_note_date, $note->created_at) + [
                'doc.original_number' => $note->invoice?->invoice_number, 'doc.reason' => $note->reason,
            ] + $this->customer($note->customer) + [
                'totals.gross' => $this->money($note->subtotal), 'totals.discount' => $this->money(0), 'totals.net' => $this->money($note->subtotal),
                'totals.vat_rate' => $this->qty($rate).'%', 'totals.vat' => $this->money($note->tax_amount), 'totals.total' => $this->money($note->total),
                'totals.paid' => $this->money(0), 'totals.remaining' => $this->money($note->total), 'totals.items_count' => '1', 'totals.quantity' => '1',
            ],
        ];
    }

    protected function receipt(CustomerPayment $payment): array
    {
        $payment->loadMissing(['customer', 'invoice:id,invoice_number', 'bankAccount']);

        return [
            'date' => $payment->payment_date?->toDateString(),
            'amount' => $payment->amount,
            'fields' => $this->doc('سند قبض', 'Receipt Voucher', $payment->payment_number, $payment->payment_date, $payment->created_at) + [
                'doc.reference' => $payment->reference_number, 'doc.payment_method' => self::METHODS[$payment->payment_method] ?? $payment->payment_method,
                'doc.bank' => $payment->bankAccount?->name, 'doc.for' => $payment->invoice ? 'سداد الفاتورة '.$payment->invoice->invoice_number : 'دفعة على الحساب',
                'doc.notes' => $payment->notes,
            ] + $this->customer($payment->customer) + ['totals.total' => $this->money($payment->amount)],
        ];
    }

    protected function supplierPayment(SupplierPayment $payment): array
    {
        $payment->loadMissing(['supplier', 'purchaseInvoice', 'bankAccount']);
        $supplier = $payment->supplier;

        return [
            'date' => $payment->payment_date?->toDateString(),
            'amount' => $payment->amount,
            'fields' => $this->doc('سند صرف', 'Payment Voucher', $payment->payment_number, $payment->payment_date, $payment->created_at) + [
                'doc.reference' => $payment->reference_number, 'doc.payment_method' => self::METHODS[$payment->payment_method] ?? $payment->payment_method,
                'doc.bank' => $payment->bankAccount?->name,
                'doc.for' => $payment->purchaseInvoice ? 'سداد فاتورة المورد '.($payment->purchaseInvoice->supplier_invoice_number ?: $payment->purchaseInvoice->invoice_number) : 'دفعة على الحساب',
                'doc.notes' => $payment->notes,
            ] + $this->supplier($supplier) + ['totals.total' => $this->money($payment->amount)],
        ];
    }

    // ------------------------------------------------------------ parts

    protected function company(Company $company): array
    {
        $parts = ['company.building_number' => $company->setting('seller_building_number'), 'company.street' => $company->setting('seller_street'),
            'company.district' => $company->setting('seller_district'), 'company.city' => $company->setting('seller_city'),
            'company.postal_code' => $company->setting('seller_postal_code'), 'company.additional_number' => $company->setting('seller_additional_number')];

        return [
            'company.name' => $company->name, 'company.vat' => $company->tax_number, 'company.cr' => $company->commercial_registration,
            'company.phone' => $company->phone, 'company.email' => $company->email,
            'company.address' => collect([$parts['company.building_number'], $parts['company.street'], $parts['company.district'], $parts['company.city'], $parts['company.postal_code']])->filter()->implode('، '),
        ] + $parts;
    }

    protected function doc(string $title, string $titleEn, ?string $number, $date, $createdAt): array
    {
        return ['doc.title' => $title, 'doc.title_en' => $titleEn, 'doc.number' => $number, 'doc.date' => $this->date($date),
            'doc.time' => $createdAt ? Carbon::parse($createdAt)->timezone(config('app.timezone'))->format('H:i') : ''];
    }

    protected function customer(?Customer $customer): array
    {
        if (! $customer) {
            return ['party.name' => 'عميل نقدي'];
        }

        // Districts are often typed with their prefix ("حي الجامعة"); templates add "حي" themselves.
        $district = trim((string) preg_replace('/^\s*(ال)?حي\s+/u', '', (string) $customer->district)) ?: null;

        return [
            'party.name' => $customer->company_name ?: $customer->name, 'party.code' => $customer->code ?: (string) $customer->id, 'party.vat' => $customer->tax_number,
            'party.cr' => $customer->commercial_registration, 'party.phone' => $customer->mobile ?: $customer->phone, 'party.email' => $customer->email,
            'party.address' => collect([$customer->building_number, $customer->street_name, $customer->district, $customer->city, $customer->postal_code])->filter()->implode('، ') ?: $customer->address,
            'party.national_address' => collect([
                $customer->building_number ? 'مبنى '.$customer->building_number : null, $customer->street_name, $district ? 'حي '.$district : null,
                trim($customer->city.' '.$customer->postal_code) ?: null, $customer->additional_details ? 'الرقم الإضافي '.$customer->additional_details : null,
            ])->filter()->implode('، ') ?: $customer->address,
            'party.building_number' => $customer->building_number, 'party.street' => $customer->street_name, 'party.district' => $district,
            'party.city' => $customer->city, 'party.postal_code' => $customer->postal_code, 'party.additional_number' => $customer->additional_details,
            'party.country' => $customer->country ?: 'السعودية',
        ];
    }

    protected function supplier(?Supplier $supplier): array
    {
        return $supplier ? [
            'party.name' => $supplier->company_name ?: $supplier->name, 'party.code' => (string) $supplier->id, 'party.vat' => $supplier->tax_number,
            'party.cr' => $supplier->commercial_register, 'party.phone' => $supplier->phone, 'party.email' => $supplier->email, 'party.address' => $supplier->address,
        ] : [];
    }

    protected function items($items): array
    {
        return $items->values()->map(fn ($item, $i) => $this->row(
            $i + 1, $item->product?->sku ?? '', $item->item_name, $item->description ?? null, $item->product?->unit?->name_ar ?? '',
            (float) $item->quantity, (float) $item->unit_price, (float) ($item->discount ?? 0), (float) $item->tax_rate,
            isset($item->tax_amount) ? (float) $item->tax_amount : null,
        ))->all();
    }

    protected function row(int $index, string $sku, ?string $name, ?string $description, string $unit, float $qty, float $price, float $discount, float $rate, ?float $tax = null): array
    {
        $gross = round($qty * $price, 2);
        $net = round($gross - $discount, 2);
        $tax ??= round($net * $rate / 100, 2);

        return [
            'index' => (string) $index, 'sku' => $sku, 'name' => (string) $name, 'description' => (string) $description,
            'name_description' => trim($name."\n".$description), 'unit' => $unit, 'quantity' => $this->qty($qty), 'unit_price' => $this->money($price),
            'gross' => $this->money($gross), 'discount' => $this->money($discount), 'net' => $this->money($net), 'tax_rate' => $this->qty($rate),
            'tax_amount' => $this->money($tax), 'total' => $this->money($net + $tax),
        ];
    }

    protected function totals($items, $discount, $tax, $total, $paid = null, $remaining = null): array
    {
        $gross = $items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_price);
        $lineDiscounts = $items->sum(fn ($i) => (float) ($i->discount ?? 0));
        $discount = max((float) $discount, $lineDiscounts);
        $net = $gross - $discount;
        $rates = $items->pluck('tax_rate')->map(fn ($r) => (float) $r)->unique();

        return [
            'totals.gross' => $this->money($gross), 'totals.discount' => $this->money($discount), 'totals.net' => $this->money($net),
            'totals.vat_rate' => $rates->count() === 1 ? $this->qty($rates->first()).'%' : '', 'totals.vat' => $this->money($tax),
            'totals.total' => $this->money($total), 'totals.paid' => $this->money($paid ?? 0), 'totals.remaining' => $this->money($remaining ?? $total),
            'totals.items_count' => (string) $items->count(), 'totals.quantity' => $this->qty($items->sum(fn ($i) => (float) $i->quantity)),
        ];
    }

    /** [currency words, fraction words, short label] */
    protected function currency(Model $document): array
    {
        $base = strtoupper((string) (Company::find($document->company_id)?->currency ?: 'SAR'));
        $code = strtoupper((string) ($document->currency_code ?: $base));
        [$name, $fraction, $symbol] = CurrencyNames::for($code);

        // [words, fraction words, short label]: the base currency by its symbol, others by their code.
        return [$name, $fraction, $code === $base ? $symbol : $code];
    }

    protected function date($value): string
    {
        return $value ? Carbon::parse($value)->format('Y/m/d') : '';
    }

    protected function money($value): string
    {
        return number_format((float) $value, 2);
    }

    protected function qty($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}

<?php

namespace App\Services\Documents;

/**
 * Starting layouts for the free-form designer, one per document kind. They
 * are ordinary layouts: every element can be moved, resized, restyled or
 * deleted. Also used to print a kind the company has not designed yet
 * (sales orders and vouchers have no built-in quick layout).
 */
class FreeformPresets
{
    protected int $n = 0;

    public static function for(string $kind): array
    {
        return in_array($kind, ['receipt', 'payment'], true) ? (new self)->voucher($kind) : (new self)->document($kind);
    }

    protected function document(string $kind): array
    {
        $meta = match ($kind) {
            'invoice' => "رقم الفاتورة: {{doc.number}}\nتاريخ الإصدار: {{doc.date}}  {{doc.time}}\nتاريخ التوريد: {{doc.supply_date}}\nالتاريخ الهجري: {{doc.hijri_date}}",
            'quotation' => "رقم العرض: {{doc.number}}\nالتاريخ: {{doc.date}}\nصالح حتى: {{doc.valid_until}}",
            'sales_order' => "رقم الأمر: {{doc.number}}\nالتاريخ: {{doc.date}}\nتاريخ التسليم: {{doc.delivery_date}}",
            default => "رقم الإشعار: {{doc.number}}\nالتاريخ: {{doc.date}}\nالفاتورة الأصلية: {{doc.original_number}}\nالسبب: {{doc.reason}}",
        };
        $withQr = in_array($kind, ['invoice', 'credit_note', 'debit_note'], true);

        $elements = [
            $this->text(110, 10, 90, 8, '{{company.name}}', ['size' => 14, 'bold' => true]),
            $this->text(110, 18, 90, 22, "الرقم الضريبي: {{company.vat}}\nالسجل التجاري: {{company.cr}}\n{{company.address}}\nهاتف: {{company.phone}}", ['size' => 8.5, 'valign' => 'top']),
            ['type' => 'image', 'name' => 'الشعار', 'x' => 72, 'y' => 9, 'w' => 66, 'h' => 26, 'src' => 'logo', 'fit' => 'contain'],
            $this->text(10, 10, 38, 7, 'الصفحة {{page.number}} / {{page.total}}', ['size' => 8.5, 'align' => 'center', 'border_width' => 0.3]),
            $this->text(68, 38, 74, 11, '{{doc.title}}  {{doc.title_en}}', ['size' => 13, 'bold' => true, 'align' => 'center', 'border_width' => 0.5, 'radius' => 1]),
            $this->text(116, 52, 84, 21, $meta, ['size' => 9, 'valign' => 'top', 'border_width' => 0.3, 'padding' => 1.5]),
            $this->text(10, 52, 60, 9, $kind === 'invoice' ? 'طريقة الدفع: {{doc.payment_type}} — {{doc.payment_method}}' : 'المرجع: {{doc.reference}}', ['size' => 9, 'border_width' => 0.3, 'align' => 'center']),
            $this->text(10, 76, 190, 27, "العميل: {{party.name}}          رقم العميل: {{party.code}}\nالرقم الضريبي: {{party.vat}}          الجوال: {{party.phone}}\nالعنوان الوطني: مبنى {{party.building_number}}، {{party.street}}، حي {{party.district}}، {{party.city}} {{party.postal_code}} — الرقم الإضافي {{party.additional_number}}",
                ['size' => 9.5, 'valign' => 'top', 'border_width' => 0.3, 'padding' => 2, 'line_height' => 1.6]),
            $this->table(10, 107, 190, 112),
            // Totals: last page only.
            ...$this->totals(10, 222),
            $this->text(10, 255, 190, 9, 'المبلغ كتابةً: {{totals.words}}', ['size' => 9.5, 'bold' => true, 'border_width' => 0.3, 'bg' => '#f8fafc'], 'last'),
            $this->text($withQr ? 118 : 160, 222, $withQr ? 42 : 40, 30, "ملاحظات:\n{{doc.notes}}", ['size' => 8.5, 'valign' => 'top'], 'last'),
        ];
        if ($withQr) {
            $elements[] = ['type' => 'qr', 'name' => 'رمز QR', 'x' => 165, 'y' => 220, 'w' => 35, 'h' => 33, 'pages' => 'last'];
        }
        array_push($elements,
            $this->text(140, 270, 60, 8, 'المستلم: ....................', ['size' => 9], 'last'),
            $this->text(75, 270, 60, 8, 'المحاسب: ....................', ['size' => 9], 'last'),
            $this->text(10, 270, 60, 8, 'الختم والتوقيع: ..............', ['size' => 9], 'last'),
            $this->line(10, 284, 190),
            $this->text(10, 285, 190, 6, '{{company.name}} — {{company.phone}} — {{company.email}}', ['size' => 7.5, 'align' => 'center', 'color' => '#475569']),
        );

        return $this->layout('A4', $elements);
    }

    protected function voucher(string $kind): array
    {
        $party = $kind === 'receipt' ? 'استلمنا من السادة' : 'صرفنا إلى السادة';
        $dotted = ['size' => 11, 'border_width' => 0.3, 'border_style' => 'dotted', 'border_sides' => ['top' => false, 'right' => false, 'bottom' => true, 'left' => false]];

        $elements = [
            $this->text(120, 8, 80, 8, '{{company.name}}', ['size' => 13, 'bold' => true]),
            $this->text(120, 16, 80, 12, "الرقم الضريبي: {{company.vat}}\nهاتف: {{company.phone}}", ['size' => 8, 'valign' => 'top']),
            ['type' => 'image', 'name' => 'الشعار', 'x' => 80, 'y' => 6, 'w' => 50, 'h' => 20, 'src' => 'logo', 'fit' => 'contain'],
            $this->text(10, 8, 60, 7, 'رقم السند: {{doc.number}}', ['size' => 10, 'bold' => true]),
            $this->text(10, 15, 60, 13, "التاريخ: {{doc.date}}\n{{doc.hijri_date}}", ['size' => 9, 'valign' => 'top']),
            $this->text(68, 29, 74, 11, '{{doc.title}}  {{doc.title_en}}', ['size' => 13, 'bold' => true, 'align' => 'center', 'border_width' => 0.5, 'radius' => 2]),
            $this->text(10, 31, 55, 11, '{{totals.total}} {{totals.currency}}', ['size' => 13, 'bold' => true, 'align' => 'center', 'border_width' => 0.5, 'bg' => '#f1f5f9', 'dir' => 'ltr']),
            $this->text(10, 50, 190, 10, $party.': {{party.name}}', $dotted),
            $this->text(10, 63, 190, 10, 'مبلغاً وقدره: {{totals.words}}', $dotted),
            $this->text(10, 76, 190, 10, 'وذلك عن: {{doc.for}}', $dotted),
            $this->text(10, 89, 190, 10, 'طريقة الدفع: {{doc.payment_method}}     البنك / الصندوق: {{doc.bank}}     المرجع: {{doc.reference}}', array_merge($dotted, ['size' => 10])),
            $this->text(10, 102, 190, 8, 'ملاحظات: {{doc.notes}}', ['size' => 9]),
            $this->text(140, 122, 60, 14, "المستلم\n....................", ['size' => 10, 'align' => 'center']),
            $this->text(75, 122, 60, 14, "المحاسب\n....................", ['size' => 10, 'align' => 'center']),
            $this->text(10, 122, 60, 14, "المدير المالي\n....................", ['size' => 10, 'align' => 'center']),
        ];

        return $this->layout('A5', $elements, 'landscape');
    }

    /** @return array[] label/value rows on the left (RTL: the end) of the page */
    protected function totals(float $x, float $y): array
    {
        $rows = [
            ['المجموع قبل الخصم', '{{totals.gross}}', false],
            ['الخصم', '{{totals.discount}}', false],
            ['الإجمالي بعد الخصم', '{{totals.net}}', false],
            ['ضريبة القيمة المضافة {{totals.vat_rate}}', '{{totals.vat}}', false],
            ['الإجمالي شامل الضريبة', '{{totals.total}} {{totals.currency}}', true],
        ];
        $out = [];
        foreach ($rows as $i => [$label, $value, $strong]) {
            $top = $y + $i * 6.4;
            $out[] = $this->text($x + 42, $top, 64, 6, $label.':', ['size' => 9, 'bold' => $strong], 'last');
            $out[] = $this->text($x, $top, 40, 6, $value, ['size' => $strong ? 10 : 9, 'bold' => $strong, 'align' => 'center', 'dir' => 'ltr', 'border_width' => 0.3,
                'bg' => $strong ? '#e2e8f0' : null], 'last');
        }

        return $out;
    }

    protected function table(float $x, float $y, float $w, float $h): array
    {
        $cols = [['index', 5], ['sku', 10], ['name_description', 33], ['unit', 8], ['quantity', 8], ['unit_price', 11], ['gross', 12], ['discount', 9],
            ['tax_rate', 6], ['tax_amount', 10], ['total', 13]];
        $known = array_column(TemplateData::columns(), null, 'key');

        return $this->base('table', 'جدول الأصناف', $x, $y, $w, $h, ['size' => 8.5, 'border_width' => 0.3]) + [
            'columns' => array_map(fn ($c) => ['key' => $c[0], 'label' => $known[$c[0]]['label'], 'label_en' => $known[$c[0]]['label_en'], 'width' => $c[1],
                'align' => $known[$c[0]]['align']], $cols),
            'header_height' => 10, 'row_height' => 9, 'header_bg' => '#f1f5f9', 'header_color' => '#0f172a', 'header_size' => 8,
            'grid_color' => '#000000', 'grid_width' => 0.2, 'row_lines' => false, 'col_lines' => true, 'stripe' => null,
        ];
    }

    protected function text(float $x, float $y, float $w, float $h, string $content, array $style = [], string $pages = 'all'): array
    {
        $name = mb_substr(preg_replace('/\s+/u', ' ', $content), 0, 30);

        return $this->base('text', $name, $x, $y, $w, $h, $style, $pages) + ['content' => $content];
    }

    protected function line(float $x, float $y, float $w): array
    {
        return $this->base('line', 'خط', $x, $y - 1, $w, 2, ['border_width' => 0.3, 'border_color' => '#94a3b8']) + ['orientation' => 'h'];
    }

    protected function base(string $type, string $name, float $x, float $y, float $w, float $h, array $style = [], string $pages = 'all'): array
    {
        return ['id' => 'p'.(++$this->n), 'type' => $type, 'name' => $name, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'pages' => $pages, 'style' => $style];
    }

    protected function layout(string $size, array $elements, string $orientation = 'portrait'): array
    {
        return ['page' => ['size' => $size, 'orientation' => $orientation, 'font' => 'xbriyaz', 'background' => ['src' => null, 'print' => false, 'opacity' => 0.35]],
            'grid' => 1, 'elements' => $elements];
    }
}

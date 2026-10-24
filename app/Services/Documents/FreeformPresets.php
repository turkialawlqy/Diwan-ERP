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

    /** Accent colour of the starting layouts (editable in the designer like any other colour). */
    protected const ACCENT = '#1e3a5f';

    protected const SOFT = '#f1f5f9';

    protected const MUTED = '#475569';

    protected function document(string $kind): array
    {
        $meta = match ($kind) {
            'invoice' => "رقم الفاتورة: {{doc.number}}\nتاريخ الإصدار: {{doc.date}}  {{doc.time}}\nتاريخ التوريد: {{doc.supply_date}}\nالتاريخ الهجري: {{doc.hijri_date}}\nالدفع: {{doc.payment_type}} — {{doc.payment_method}}",
            'quotation' => "رقم العرض: {{doc.number}}\nالتاريخ: {{doc.date}}\nالتاريخ الهجري: {{doc.hijri_date}}\nصالح حتى: {{doc.valid_until}}",
            'sales_order' => "رقم الأمر: {{doc.number}}\nالتاريخ: {{doc.date}}\nالتاريخ الهجري: {{doc.hijri_date}}\nتاريخ التسليم: {{doc.delivery_date}}",
            default => "رقم الإشعار: {{doc.number}}\nالتاريخ: {{doc.date}}\nالفاتورة الأصلية: {{doc.original_number}}\nالسبب: {{doc.reason}}",
        };
        $withQr = in_array($kind, ['invoice', 'credit_note', 'debit_note'], true);
        $white = ['color' => '#ffffff', 'bold' => true];

        $elements = [
            // Header: company on the right, logo on the left, a coloured title band under both.
            $this->box(0, 0, 210, 3, ['bg' => self::ACCENT]),
            $this->text(80, 9, 120, 9, '{{company.name}}', ['size' => 15, 'bold' => true, 'color' => self::ACCENT]),
            $this->text(80, 18, 120, 18, "الرقم الضريبي: {{company.vat}}     السجل التجاري: {{company.cr}}\n{{company.address}}\nهاتف: {{company.phone}}     {{company.email}}",
                ['size' => 8.5, 'valign' => 'top', 'color' => self::MUTED, 'line_height' => 1.5]),
            ['type' => 'image', 'name' => 'الشعار', 'x' => 10, 'y' => 8, 'w' => 55, 'h' => 30, 'src' => 'logo', 'fit' => 'contain'],
            $this->box(10, 41, 190, 12, ['bg' => self::ACCENT, 'radius' => 1.5]),
            $this->text(105, 41, 92, 12, '{{doc.title}}', ['size' => 15] + $white),
            $this->text(13, 41, 92, 12, '{{doc.title_en}}', ['size' => 12, 'align' => 'left', 'dir' => 'ltr'] + $white),

            // Customer card (right) and document details (left).
            $this->box(76, 57, 124, 36, ['border_width' => 0.3, 'border_color' => '#cbd5e1', 'radius' => 1.5]),
            $this->text(79, 58, 118, 6, $kind === 'quotation' ? 'مقدّم إلى' : 'العميل', ['size' => 8, 'bold' => true, 'color' => self::ACCENT]),
            $this->text(79, 64, 118, 7, '{{party.name}}', ['size' => 10.5, 'bold' => true]),
            $this->text(79, 71, 118, 21, "الرقم الضريبي: {{party.vat}}     رقم العميل: {{party.code}}\nالجوال: {{party.phone}}\nالعنوان الوطني: {{party.national_address}}",
                ['size' => 8.5, 'valign' => 'top', 'line_height' => 1.55, 'color' => '#334155']),
            $this->box(10, 57, 63, 36, ['bg' => self::SOFT, 'radius' => 1.5]),
            $this->text(12, 59, 59, 32, $meta, ['size' => 8.5, 'valign' => 'top', 'line_height' => 1.7]),

            $this->table(10, 97, 190, 120),

            // Last page: amount in words, notes, QR and totals.
            ...$this->totals(10, 221),
            $this->text(95, 221, $withQr ? 65 : 105, 16, "المبلغ كتابةً:\n{{totals.words}}",
                ['size' => 8.5, 'bold' => true, 'valign' => 'top', 'bg' => self::SOFT, 'radius' => 1.5, 'padding' => 2, 'line_height' => 1.5], 'last'),
            $this->text(95, 239, $withQr ? 65 : 105, 15, 'ملاحظات: {{doc.notes}}', ['size' => 8, 'valign' => 'top', 'color' => self::MUTED, 'padding' => 2], 'last'),
        ];
        if ($withQr) {
            $elements[] = ['type' => 'qr', 'name' => 'رمز QR', 'x' => 166, 'y' => 220, 'w' => 34, 'h' => 34, 'pages' => 'last'];
        }
        $signature = ['size' => 9, 'align' => 'center', 'color' => self::MUTED, 'border_width' => 0.3, 'border_style' => 'dotted', 'border_color' => '#94a3b8',
            'border_sides' => ['top' => true, 'right' => false, 'bottom' => false, 'left' => false]];
        array_push($elements,
            $this->text(145, 270, 55, 8, 'المستلم', $signature, 'last'),
            $this->text(77.5, 270, 55, 8, 'المحاسب', $signature, 'last'),
            $this->text(10, 270, 55, 8, 'الختم والتوقيع', $signature, 'last'),
            $this->line(10, 284, 190),
            $this->text(55, 285, 145, 6, '{{company.name}} — {{company.phone}} — {{company.email}}', ['size' => 7.5, 'color' => self::MUTED]),
            $this->text(10, 285, 45, 6, 'صفحة {{page.number}} من {{page.total}}', ['size' => 7.5, 'align' => 'left', 'color' => self::MUTED]),
            $this->box(0, 294, 210, 3, ['bg' => self::ACCENT]),
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

    /** @return array[] label/value rows on the left (RTL: the end) of the page, the grand total on a coloured band */
    protected function totals(float $x, float $y): array
    {
        $rows = [
            ['المجموع قبل الخصم', '{{totals.gross}}', false],
            ['الخصم', '{{totals.discount}}', false],
            ['الإجمالي بعد الخصم', '{{totals.net}}', false],
            ['ضريبة القيمة المضافة {{totals.vat_rate}}', '{{totals.vat}}', false],
            ['الإجمالي شامل الضريبة', '{{totals.total}} {{totals.currency}}', true],
        ];
        $rule = ['border_width' => 0.2, 'border_color' => '#e2e8f0', 'border_sides' => ['top' => false, 'right' => false, 'bottom' => true, 'left' => false]];
        $out = [];
        foreach ($rows as $i => [$label, $value, $strong]) {
            $top = $y + $i * 6.6 + ($strong ? 1 : 0);
            $style = $strong ? ['bg' => self::ACCENT, 'color' => '#ffffff', 'bold' => true, 'size' => 10] : $rule + ['size' => 9];
            if ($strong) {
                $out[] = $this->box($x, $top, 80, 8, ['bg' => self::ACCENT, 'radius' => 1.5], 'last');
                unset($style['bg']);
            }
            $out[] = $this->text($x + 36, $top, 44, $strong ? 8 : 6.4, $label, $style + ['padding' => 1.5, 'color' => self::MUTED], 'last');
            $out[] = $this->text($x, $top, 36, $strong ? 8 : 6.4, $value, $style + ['align' => 'left', 'dir' => 'ltr', 'bold' => true, 'padding' => 1.5], 'last');
        }

        return $out;
    }

    protected function table(float $x, float $y, float $w, float $h): array
    {
        $cols = [['index', 5], ['name_description', 37], ['unit', 8], ['quantity', 8], ['unit_price', 11], ['discount', 9], ['tax_rate', 7], ['tax_amount', 11],
            ['total', 13]];
        $known = array_column(TemplateData::columns(), null, 'key');

        return $this->base('table', 'جدول الأصناف', $x, $y, $w, $h, ['size' => 8.5, 'border_width' => 0.3, 'border_color' => '#cbd5e1']) + [
            'columns' => array_map(fn ($c) => ['key' => $c[0], 'label' => $known[$c[0]]['label'], 'label_en' => $known[$c[0]]['label_en'], 'width' => $c[1],
                'align' => $known[$c[0]]['align']], $cols),
            'header_height' => 10, 'row_height' => 9, 'header_bg' => self::ACCENT, 'header_color' => '#ffffff', 'header_size' => 8,
            'grid_color' => '#e2e8f0', 'grid_width' => 0.2, 'row_lines' => true, 'col_lines' => false, 'stripe' => '#f8fafc',
        ];
    }

    protected function text(float $x, float $y, float $w, float $h, string $content, array $style = [], string $pages = 'all'): array
    {
        $name = mb_substr(preg_replace('/\s+/u', ' ', $content), 0, 30);

        return $this->base('text', $name, $x, $y, $w, $h, $style, $pages) + ['content' => $content];
    }

    protected function box(float $x, float $y, float $w, float $h, array $style, string $pages = 'all'): array
    {
        return $this->base('box', 'مستطيل', $x, $y, $w, $h, $style, $pages);
    }

    protected function line(float $x, float $y, float $w): array
    {
        return $this->base('line', 'خط', $x, $y - 1, $w, 2, ['border_width' => 0.3, 'border_color' => '#cbd5e1']) + ['orientation' => 'h'];
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

<?php

namespace App\Services\Documents;

/**
 * Free-form print layouts: elements placed anywhere on the page in
 * millimetres. sanitize() is the only way a layout from the designer reaches
 * storage, so every value the renderers trust is bounded here (numbers
 * clamped, colours and enums whitelisted, images limited to the company's
 * own uploads).
 */
class FreeformLayout
{
    public const PAPERS = ['A4' => [210, 297], 'A5' => [148, 210], 'Letter' => [216, 279], 'Legal' => [216, 356]];

    /** key => [label, mPDF font, ttf file for the browser (bold variant optional)] */
    public const FONTS = [
        'xbriyaz' => ['نسخ (XB Riyaz)', 'XB Riyaz.ttf', 'XB RiyazBd.ttf'],
        'dejavusans' => ['DejaVu Sans', 'DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'],
        'dejavusanscondensed' => ['DejaVu Sans مضغوط', 'DejaVuSansCondensed.ttf', 'DejaVuSansCondensed-Bold.ttf'],
        'lateef' => ['لطيف (Lateef)', 'LateefRegOT.ttf', null],
    ];

    public const TYPES = ['text', 'image', 'qr', 'line', 'box', 'table'];

    public const PAGES = ['all', 'first', 'last', 'not_first', 'not_last'];

    public static function sanitize(array $layout, int $companyId): array
    {
        $page = $layout['page'] ?? [];
        $size = isset(self::PAPERS[$page['size'] ?? '']) ? $page['size'] : ((($page['size'] ?? '') === 'custom') ? 'custom' : 'A4');
        [$w, $h] = $size === 'custom'
            ? [self::num($page['width'] ?? 210, 50, 600), self::num($page['height'] ?? 297, 50, 1200)]
            : array_map('floatval', self::PAPERS[$size]);
        if (($page['orientation'] ?? 'portrait') === 'landscape' && $size !== 'custom') {
            [$w, $h] = [$h, $w];
        }
        $background = $page['background'] ?? [];

        $elements = [];
        foreach (array_slice(array_values($layout['elements'] ?? []), 0, 300) as $i => $el) {
            if (is_array($el) && in_array($el['type'] ?? null, self::TYPES, true)) {
                $elements[] = self::element($el, $i, $w, $h, $companyId);
            }
        }

        return [
            'page' => [
                'size' => $size,
                'orientation' => ($page['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait',
                'width' => $w,
                'height' => $h,
                'background' => [
                    'src' => self::image($background['src'] ?? null, $companyId, false),
                    'print' => (bool) ($background['print'] ?? false),
                    'opacity' => self::num($background['opacity'] ?? 0.35, 0.05, 1),
                ],
                'font' => isset(self::FONTS[$page['font'] ?? '']) ? $page['font'] : 'xbriyaz',
            ],
            'grid' => self::num($layout['grid'] ?? 1, 0.5, 10),
            'elements' => $elements,
        ];
    }

    protected static function element(array $el, int $i, float $pw, float $ph, int $companyId): array
    {
        $type = $el['type'];
        $s = $el['style'] ?? [];
        $sides = $s['border_sides'] ?? [];

        $out = [
            'id' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($el['id'] ?? '')) ?: 'el'.$i,
            'type' => $type,
            'name' => mb_substr(strip_tags((string) ($el['name'] ?? '')), 0, 60),
            'x' => self::num($el['x'] ?? 0, -50, $pw),
            'y' => self::num($el['y'] ?? 0, -50, $ph),
            'w' => self::num($el['w'] ?? 40, 0.1, $pw + 50),
            'h' => self::num($el['h'] ?? 10, 0.1, $ph + 50),
            'pages' => in_array($el['pages'] ?? 'all', self::PAGES, true) ? ($el['pages'] ?? 'all') : 'all',
            'locked' => (bool) ($el['locked'] ?? false),
            'style' => [
                'font' => isset(self::FONTS[$s['font'] ?? '']) ? $s['font'] : null,
                'size' => self::num($s['size'] ?? 10, 4, 96),
                'bold' => (bool) ($s['bold'] ?? false),
                'italic' => (bool) ($s['italic'] ?? false),
                'underline' => (bool) ($s['underline'] ?? false),
                'color' => self::color($s['color'] ?? '#000000') ?? '#000000',
                'bg' => self::color($s['bg'] ?? null),
                'align' => in_array($s['align'] ?? 'right', ['right', 'center', 'left', 'justify'], true) ? ($s['align'] ?? 'right') : 'right',
                'valign' => in_array($s['valign'] ?? 'middle', ['top', 'middle', 'bottom'], true) ? ($s['valign'] ?? 'middle') : 'middle',
                'dir' => ($s['dir'] ?? 'rtl') === 'ltr' ? 'ltr' : 'rtl',
                'line_height' => self::num($s['line_height'] ?? 1.3, 0.8, 3),
                'padding' => self::num($s['padding'] ?? 1, 0, 20),
                'border_width' => self::num($s['border_width'] ?? 0, 0, 5),
                'border_color' => self::color($s['border_color'] ?? '#000000') ?? '#000000',
                'border_style' => in_array($s['border_style'] ?? 'solid', ['solid', 'dashed', 'dotted', 'double'], true) ? ($s['border_style'] ?? 'solid') : 'solid',
                'border_sides' => [
                    'top' => (bool) ($sides['top'] ?? true), 'right' => (bool) ($sides['right'] ?? true),
                    'bottom' => (bool) ($sides['bottom'] ?? true), 'left' => (bool) ($sides['left'] ?? true),
                ],
                'radius' => self::num($s['radius'] ?? 0, 0, 30),
            ],
        ];

        if ($type === 'text') {
            $out['content'] = mb_substr((string) ($el['content'] ?? ''), 0, 3000);
        } elseif ($type === 'image') {
            $out['src'] = self::image($el['src'] ?? 'logo', $companyId, true);
            $out['fit'] = in_array($el['fit'] ?? 'contain', ['contain', 'stretch'], true) ? ($el['fit'] ?? 'contain') : 'contain';
        } elseif ($type === 'line') {
            $out['orientation'] = ($el['orientation'] ?? 'h') === 'v' ? 'v' : 'h';
            $out['style']['border_width'] = self::num($s['border_width'] ?? 0.3, 0.05, 5);
        } elseif ($type === 'table') {
            $known = array_column(TemplateData::columns(), null, 'key');
            $columns = [];
            foreach (array_slice($el['columns'] ?? [], 0, 20) as $col) {
                if (isset($known[$col['key'] ?? null])) {
                    $columns[] = [
                        'key' => $col['key'],
                        'label' => mb_substr((string) ($col['label'] ?? $known[$col['key']]['label']), 0, 60),
                        'label_en' => mb_substr((string) ($col['label_en'] ?? ''), 0, 60),
                        'width' => self::num($col['width'] ?? 10, 1, 100),
                        'align' => in_array($col['align'] ?? 'center', ['right', 'center', 'left'], true) ? ($col['align'] ?? 'center') : 'center',
                    ];
                }
            }
            $out['columns'] = $columns ?: [['key' => 'name', 'label' => 'الصنف', 'label_en' => '', 'width' => 100, 'align' => 'right']];
            $out['header_height'] = self::num($el['header_height'] ?? 10, 3, 40);
            $out['row_height'] = self::num($el['row_height'] ?? 7, 3, 60);
            $out['header_bg'] = self::color($el['header_bg'] ?? '#f1f5f9');
            $out['header_color'] = self::color($el['header_color'] ?? '#000000') ?? '#000000';
            $out['header_size'] = self::num($el['header_size'] ?? 9, 4, 30);
            $out['grid_color'] = self::color($el['grid_color'] ?? '#000000') ?? '#000000';
            $out['grid_width'] = self::num($el['grid_width'] ?? 0.2, 0, 3);
            $out['row_lines'] = (bool) ($el['row_lines'] ?? false);
            $out['col_lines'] = (bool) ($el['col_lines'] ?? true);
            $out['stripe'] = self::color($el['stripe'] ?? null);
        }

        return $out;
    }

    /** Only "logo" or a file this company uploaded through the designer. */
    public static function image($src, int $companyId, bool $allowLogo): ?string
    {
        if ($allowLogo && $src === 'logo') {
            return 'logo';
        }

        return is_string($src) && preg_match('#^templates/'.$companyId.'/[A-Za-z0-9_-]{8,80}\.(png|jpe?g|gif)$#', $src) ? $src : null;
    }

    public static function color($value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    public static function num($value, float $min, float $max): float
    {
        return round(min($max, max($min, is_numeric($value) ? (float) $value : $min)), 2);
    }

    /** Item rows that fit in the table on one page. */
    public static function rowsPerPage(array $table): int
    {
        return max(1, (int) floor(($table['h'] - $table['header_height'] + 0.01) / $table['row_height']));
    }
}

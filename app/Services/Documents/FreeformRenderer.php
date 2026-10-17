<?php

namespace App\Services\Documents;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Mpdf\QrCode\Output\Png;
use Mpdf\QrCode\QrCode;

/**
 * Renders a free-form layout to PDF pages. Every element is an absolutely
 * positioned block in millimetres, so the PDF matches the designer canvas.
 * Items flow through the items table: when they do not fit, the whole design
 * repeats on the next page with the next rows, and elements can be limited to
 * the first / last page (totals on the last page only, for example).
 */
class FreeformRenderer
{
    public function __construct(protected PdfRenderer $pdf) {}

    /**
     * @param  array  $data  TemplateData::build() / sample()
     */
    public function render(array $layout, array $data, Company $company, array $attachments = [], string $title = ''): string
    {
        return $this->pdf->renderPages($this->pages($layout, $data, $company), $layout['page'], $attachments, $title, $company->name);
    }

    /** @return string[] one HTML fragment per page */
    public function pages(array $layout, array $data, Company $company): array
    {
        $tables = array_values(array_filter($layout['elements'], fn ($e) => $e['type'] === 'table'));
        $perPage = $tables ? FreeformLayout::rowsPerPage($tables[0]) : PHP_INT_MAX;
        $chunks = $tables && $data['items'] ? array_chunk($data['items'], $perPage) : [$data['items']];
        $total = count($chunks);
        $logo = $company->logoPath() ? Storage::disk('public')->path($company->logoPath()) : null;
        $qr = $data['qr'] ? $this->qrImage($data['qr']) : null;

        $pages = [];
        foreach ($chunks as $i => $rows) {
            $fields = $data['fields'] + ['page.number' => (string) ($i + 1), 'page.total' => (string) $total];
            $html = $this->background($layout['page']);
            foreach ($layout['elements'] as $el) {
                if ($this->onPage($el['pages'], $i, $total)) {
                    $html .= $this->element($el, $fields, $rows, $logo, $qr, $layout['page']['font']);
                }
            }
            $pages[] = $html;
        }

        return $pages;
    }

    protected function onPage(string $rule, int $index, int $total): bool
    {
        return match ($rule) {
            'first' => $index === 0,
            'last' => $index === $total - 1,
            'not_first' => $index > 0,
            'not_last' => $index < $total - 1,
            default => true,
        };
    }

    protected function background(array $page): string
    {
        $src = $page['background']['src'] ?? null;
        if (! $src || ! ($page['background']['print'] ?? false) || ! Storage::disk('public')->exists($src)) {
            return '';
        }

        return '<div style="position:absolute;left:0;top:0;width:'.$page['width'].'mm;height:'.$page['height'].'mm;">'
            .'<img src="'.e(Storage::disk('public')->path($src)).'" style="width:'.$page['width'].'mm;height:'.$page['height'].'mm;" /></div>';
    }

    protected function element(array $el, array $fields, array $rows, ?string $logo, ?string $qr, string $pageFont): string
    {
        $s = $el['style'];

        if ($el['type'] === 'line') {
            $border = $s['border_width'].'mm '.$s['border_style'].' '.$s['border_color'];

            return $el['orientation'] === 'v'
                ? '<div style="position:absolute;left:'.($el['x'] + $el['w'] / 2).'mm;top:'.$el['y'].'mm;width:0;height:'.$el['h'].'mm;border-left:'.$border.';"></div>'
                : '<div style="position:absolute;left:'.$el['x'].'mm;top:'.($el['y'] + $el['h'] / 2).'mm;width:'.$el['w'].'mm;height:0;border-top:'.$border.';"></div>';
        }

        $pad = in_array($el['type'], ['table', 'image', 'qr'], true) ? 0 : $s['padding'];
        $bw = $s['border_width'];
        $innerW = max(0.1, $el['w'] - 2 * $pad - ($s['border_sides']['left'] ? $bw : 0) - ($s['border_sides']['right'] ? $bw : 0));
        $innerH = max(0.1, $el['h'] - 2 * $pad - ($s['border_sides']['top'] ? $bw : 0) - ($s['border_sides']['bottom'] ? $bw : 0));

        $box = 'position:absolute;overflow:hidden;left:'.$el['x'].'mm;top:'.$el['y'].'mm;width:'.$innerW.'mm;height:'.$innerH.'mm;padding:'.$pad.'mm;'
            .$this->border($s).($s['bg'] ? 'background-color:'.$s['bg'].';' : '').($s['radius'] ? 'border-radius:'.$s['radius'].'mm;' : '');

        $inner = match ($el['type']) {
            'text' => $this->cell($this->text($el['content'], $fields), $s, $innerH, $pageFont),
            'box' => '',
            'image' => $this->image($el, $innerW, $innerH, $logo),
            'qr' => $qr ? $this->centered('<img src="'.$qr.'" style="width:'.min($innerW, $innerH).'mm;height:'.min($innerW, $innerH).'mm;" />', $innerH) : '',
            'table' => $this->table($el, $rows, $innerW, $innerH, $pageFont),
        };

        return '<div style="'.$box.'">'.$inner.'</div>';
    }

    protected function border(array $s): string
    {
        if ($s['border_width'] <= 0) {
            return 'border:none;';
        }
        $css = '';
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $css .= 'border-'.$side.':'.($s['border_sides'][$side] ? $s['border_width'].'mm '.$s['border_style'].' '.$s['border_color'] : 'none').';';
        }

        return $css;
    }

    /** Static text with {{field}} placeholders, escaped, line breaks kept. */
    public function text(string $content, array $fields): string
    {
        $html = e($content);
        $html = preg_replace_callback('/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/', fn ($m) => e($fields[$m[1]] ?? ''), $html);

        return nl2br($html, false);
    }

    protected function font(array $s, string $pageFont): string
    {
        return 'font-family:'.($s['font'] ?? $pageFont).';font-size:'.$s['size'].'pt;color:'.$s['color'].';'
            .($s['bold'] ? 'font-weight:bold;' : '').($s['italic'] ? 'font-style:italic;' : '').($s['underline'] ? 'text-decoration:underline;' : '')
            .'line-height:'.$s['line_height'].';direction:'.$s['dir'].';';
    }

    /** A one-cell table gives vertical alignment inside a fixed block. */
    protected function cell(string $html, array $s, float $height, string $pageFont): string
    {
        return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;" autosize="1"><tr>'
            .'<td style="height:'.$height.'mm;padding:0;vertical-align:'.$s['valign'].';text-align:'.$s['align'].';'.$this->font($s, $pageFont).'">'.$html.'</td>'
            .'</tr></table>';
    }

    protected function centered(string $html, float $height): string
    {
        return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;"><tr><td style="height:'.$height.'mm;padding:0;text-align:center;vertical-align:middle;">'.$html.'</td></tr></table>';
    }

    protected function image(array $el, float $w, float $h, ?string $logo): string
    {
        $path = $el['src'] === 'logo' ? $logo : ($el['src'] && Storage::disk('public')->exists($el['src']) ? Storage::disk('public')->path($el['src']) : null);
        if (! $path) {
            return '';
        }
        [$iw, $ih] = @getimagesize($path) ?: [1, 1];
        if ($el['fit'] === 'stretch' || ! $iw || ! $ih) {
            [$dw, $dh] = [$w, $h];
        } else {
            $scale = min($w / $iw, $h / $ih);
            [$dw, $dh] = [round($iw * $scale, 2), round($ih * $scale, 2)];
        }

        return $this->centered('<img src="'.e($path).'" style="width:'.$dw.'mm;height:'.$dh.'mm;" />', $h);
    }

    protected function table(array $el, array $rows, float $w, float $h, string $pageFont): string
    {
        $s = $el['style'];
        $sum = array_sum(array_column($el['columns'], 'width')) ?: 1;
        $grid = $el['grid_width'] > 0 ? $el['grid_width'].'mm solid '.$el['grid_color'] : 'none';
        $font = $this->font($s, $pageFont);
        $last = count($el['columns']) - 1;
        $columns = $el['columns'];
        // The first column sits at the right in RTL, so separators are on the
        // left edge of each cell (right edge for an LTR table).
        $edge = $s['dir'] === 'rtl' ? 'border-left:' : 'border-right:';

        $colgroup = '';
        foreach ($columns as $col) {
            $colgroup .= '<col style="width:'.round($w * $col['width'] / $sum, 2).'mm;" />';
        }

        $head = '';
        foreach ($columns as $i => $col) {
            $side = $el['col_lines'] && $i < $last ? $edge.$grid.';' : '';
            $label = e($col['label']).($col['label_en'] !== '' ? '<br/>'.e($col['label_en']) : '');
            $head .= '<td style="height:'.$el['header_height'].'mm;padding:0 0.8mm;text-align:center;vertical-align:middle;font-weight:bold;'
                .'font-size:'.$el['header_size'].'pt;color:'.$el['header_color'].';border-bottom:'.$grid.';'.$side.'">'.$label.'</td>';
        }

        $body = '';
        foreach ($rows as $r => $row) {
            $bg = $el['stripe'] && $r % 2 ? 'background-color:'.$el['stripe'].';' : '';
            $body .= '<tr>';
            foreach ($columns as $i => $col) {
                $side = $el['col_lines'] && $i < $last ? $edge.$grid.';' : '';
                $bottom = $el['row_lines'] ? 'border-bottom:'.$grid.';' : '';
                $body .= '<td style="height:'.$el['row_height'].'mm;padding:0 0.8mm;vertical-align:middle;text-align:'.$col['align'].';'.$side.$bottom.$bg.'">'
                    .nl2br(e($row[$col['key']] ?? ''), false).'</td>';
            }
            $body .= '</tr>';
        }

        // Column lines run down to the bottom of the box, as on printed forms.
        $used = $el['header_height'] + count($rows) * $el['row_height'];
        if ($h - $used > 0.5) {
            $body .= '<tr>';
            foreach ($columns as $i => $col) {
                $side = $el['col_lines'] && $i < $last ? $edge.$grid.';' : '';
                $body .= '<td style="height:'.round($h - $used - 0.3, 2).'mm;padding:0;'.$side.'"></td>';
            }
            $body .= '</tr>';
        }

        return '<table cellpadding="0" cellspacing="0" autosize="1" style="width:'.$w.'mm;border-collapse:collapse;table-layout:fixed;'.$font.'">'
            .$colgroup.'<tr style="'.($el['header_bg'] ? 'background-color:'.$el['header_bg'].';' : '').'">'.$head.'</tr>'.$body.'</table>';
    }

    protected function qrImage(string $payload): ?string
    {
        try {
            $png = (new Png)->output(new QrCode($payload, QrCode::ERROR_CORRECTION_MEDIUM), 400);

            return 'data:image/png;base64,'.base64_encode($png);
        } catch (\Throwable) {
            return null;
        }
    }
}

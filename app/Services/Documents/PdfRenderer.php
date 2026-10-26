<?php

namespace App\Services\Documents;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Renders a Blade view to an Arabic (RTL) PDF with mPDF.
 *
 * PDF/A-3 is used when files are attached, which is the form ZATCA describes
 * for sharing a tax invoice together with its signed XML.
 */
class PdfRenderer
{
    /**
     * @param  array  $attachments  [['name' => 'x.xml', 'content' => '...', 'mime' => 'text/xml', 'description' => '...']]
     */
    public function render(string $view, array $data = [], array $attachments = [], string $title = ''): string
    {
        $mpdf = $this->make();
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor($data['company']['name'] ?? config('app.name'));
        $mpdf->SetCreator(config('app.name'));

        if ($attachments) {
            $mpdf->PDFA = true;
            $mpdf->PDFAauto = true;
            $mpdf->PDFAversion = '3-B';
            $mpdf->SetAssociatedFiles(array_map(fn ($file) => [
                'name' => $file['name'],
                'mime' => $file['mime'] ?? 'application/octet-stream',
                'description' => $file['description'] ?? $file['name'],
                'AFRelationship' => $file['relationship'] ?? 'Data',
                'content' => $file['content'],
            ], $attachments));
        }

        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf->Output('', 'S');
    }

    /**
     * Free-form layouts: one HTML fragment per page, absolutely positioned on
     * a margin-less page of the template's size.
     *
     * @param  string[]  $pages
     */
    public function renderPages(array $pages, array $page, array $attachments = [], string $title = '', string $author = ''): string
    {
        $mpdf = $this->make([
            'format' => [$page['width'], $page['height']],
            'margin_top' => 0, 'margin_bottom' => 0, 'margin_left' => 0, 'margin_right' => 0,
            'margin_header' => 0, 'margin_footer' => 0,
            'default_font' => $page['font'] ?? config('documents.pdf.font', 'xbriyaz'),
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor($author ?: config('app.name'));
        $mpdf->SetCreator(config('app.name'));
        $mpdf->autoPageBreak = false;
        $this->attach($mpdf, $attachments);

        foreach ($pages as $i => $html) {
            if ($i > 0) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML('<body dir="rtl">'.$html.'</body>');
        }

        return $mpdf->Output('', 'S');
    }

    protected function attach(Mpdf $mpdf, array $attachments): void
    {
        if (! $attachments) {
            return;
        }
        $mpdf->PDFA = true;
        $mpdf->PDFAauto = true;
        $mpdf->PDFAversion = '3-B';
        $mpdf->SetAssociatedFiles(array_map(fn ($file) => [
            'name' => $file['name'],
            'mime' => $file['mime'] ?? 'application/octet-stream',
            'description' => $file['description'] ?? $file['name'],
            'AFRelationship' => $file['relationship'] ?? 'Data',
            'content' => $file['content'],
        ], $attachments));
    }

    protected function make(array $options = []): Mpdf
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];

        // A custom font (e.g. Cairo) can be dropped into resources/fonts and
        // selected with PDF_FONT / PDF_FONT_FILE; XB Riyaz ships with mPDF.
        $custom = config('documents.pdf.font_file');
        if ($custom && is_file(resource_path('fonts/'.$custom))) {
            $fontDirs[] = resource_path('fonts');
            $fontData[config('documents.pdf.font')] = ['R' => $custom, 'useOTL' => 0xFF, 'useKashida' => 75];
        }

        return new Mpdf($options + [
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 12,
            'margin_bottom' => 14,
            'margin_left' => 12,
            'margin_right' => 12,
            'tempDir' => $tempDir,
            'fontDir' => $fontDirs,
            'fontdata' => $fontData,
            'default_font' => config('documents.pdf.font', 'xbriyaz'),
            'directionality' => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);
    }
}

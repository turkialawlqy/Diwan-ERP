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

    protected function make(): Mpdf
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

        return new Mpdf([
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

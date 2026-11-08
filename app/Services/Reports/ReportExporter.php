<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Services\Documents\PdfRenderer;
use App\Services\TenantManager;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Turns a ReportCatalog table into an .xlsx (right-to-left sheet) or an A4 PDF. */
class ReportExporter
{
    public function xlsx(array $table): string
    {
        $columns = $table['columns'];
        $data = array_map(fn ($row) => array_map(fn ($c) => $row[$c['key']] ?? null, $columns), $table['rows']);
        $bold = $table['bold'] ?? [];
        $title = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $table['title']), 0, 31);

        $export = new class($table, $data, $bold, $title) implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithProperties, WithStyles, WithTitle
        {
            public function __construct(protected array $table, protected array $data, protected array $bold, protected string $title) {}

            public function array(): array
            {
                return $this->data;
            }

            public function headings(): array
            {
                return array_column($this->table['columns'], 'label');
            }

            public function title(): string
            {
                return $this->title;
            }

            /** Period and creation time travel with the file (workbook properties), keeping the sheet itself a clean table. */
            public function properties(): array
            {
                return ['title' => $this->table['title'], 'description' => $this->table['subtitle'] ?? ''];
            }

            public function styles(Worksheet $sheet): array
            {
                $styles = [1 => ['font' => ['bold' => true]]];
                foreach ($this->bold as $index) {
                    $styles[$index + 2] = ['font' => ['bold' => true]];
                }

                return $styles;
            }

            public function registerEvents(): array
            {
                return [AfterSheet::class => function (AfterSheet $event) {
                    $sheet = $event->sheet->getDelegate();
                    $sheet->setRightToLeft(true);
                    foreach ($this->table['columns'] as $i => $column) {
                        if ($column['type'] === 'money') {
                            $letter = Coordinate::stringFromColumnIndex($i + 1);
                            $sheet->getStyle("{$letter}2:{$letter}".(count($this->data) + 1))->getNumberFormat()->setFormatCode('#,##0.00');
                        }
                    }
                }];
            }
        };

        return Excel::raw($export, ExcelWriter::XLSX);
    }

    public function pdf(array $table): string
    {
        $company = app(TenantManager::class)->getCompany() ?? Company::first();

        return app(PdfRenderer::class)->render('pdf.report', ['table' => $table, 'companyName' => $company?->name, 'taxNumber' => $company?->tax_number], [], $table['title']);
    }
}

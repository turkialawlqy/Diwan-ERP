<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Excel / PDF download of any report in the catalog, with the filters of the page it is downloaded from. */
class ReportExportController extends Controller
{
    public function download(Request $request, string $report, string $format, ReportCatalog $catalog, ReportExporter $exporter): Response
    {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true) && isset(ReportCatalog::PERMISSIONS[$report]), 404);
        abort_unless($request->user()->can(ReportCatalog::PERMISSIONS[$report]), 403, 'ليس لديك صلاحية تصدير هذا التقرير.');

        $table = $catalog->build($report, $request);
        // Who took which report out of the system, with its filters (read in «سجل التدقيق»).
        AuditLog::record('report.exported', null, [], ['report' => $report, 'format' => $format, 'filters' => $request->except(['_token'])]);
        $name = $report.'-'.now()->format('Ymd');

        return $format === 'xlsx'
            ? response($exporter->xlsx($table), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\""])
            : response($exporter->pdf($table), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.pdf\""]);
    }
}

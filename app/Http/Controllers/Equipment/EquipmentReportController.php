<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Services\Equipment\EquipmentReports;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EquipmentReportController extends Controller
{
    protected const COLUMNS = [
        'profitability' => ['code' => 'الرمز', 'name' => 'المعدة', 'hours' => 'ساعات العمل', 'revenue' => 'إيراد التأجير', 'recovery' => 'تحميل المشاريع',
            'maintenance' => 'الصيانة', 'rent_in' => 'إيجار المورد', 'depreciation' => 'الإهلاك', 'net' => 'صافي الربح', 'margin' => 'هامش %', 'cost_per_hour' => 'التكلفة/ساعة'],
        'utilization' => ['code' => 'الرمز', 'name' => 'المعدة', 'worked_hours' => 'ساعات العمل', 'available_hours' => 'الساعات المتاحة', 'utilization' => 'نسبة التشغيل %',
            'work_days' => 'أيام العمل', 'downtime_days' => 'أيام الصيانة', 'idle_days' => 'أيام التوقف'],
        'compliance' => ['code' => 'الرمز', 'name' => 'المعدة', 'serviced' => 'بنود منفذة', 'on_time' => 'في موعدها', 'late' => 'متأخرة', 'on_time_rate' => 'الالتزام %',
            'overdue_now' => 'متأخرة الآن', 'due_soon_now' => 'تستحق قريباً', 'preventive' => 'أوامر وقائية', 'corrective' => 'أوامر إصلاح', 'parts_cost' => 'تكلفة القطع',
            'cost_per_hour' => 'تكلفة/ساعة', 'cost_per_km' => 'تكلفة/كم'],
        'parts' => ['product' => 'الصنف', 'sku' => 'الرمز', 'code' => 'رمز المعدة', 'equipment' => 'المعدة', 'quantity' => 'الكمية', 'amount' => 'التكلفة', 'orders' => 'أوامر الصيانة'],
    ];

    public function index(Request $request, EquipmentReports $reports)
    {
        [$type, $from, $to, $hours] = $this->params($request);

        return Inertia::render('Equipment/Reports', [
            'type' => $type,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'standard_hours' => $hours],
            'report' => $this->run($reports, $type, $from, $to, $hours),
            'columns' => self::COLUMNS[$type],
        ]);
    }

    public function export(Request $request, EquipmentReports $reports): StreamedResponse
    {
        [$type, $from, $to, $hours] = $this->params($request);
        $report = $this->run($reports, $type, $from, $to, $hours);
        $columns = self::COLUMNS[$type];

        return response()->streamDownload(function () use ($report, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($columns));
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(function ($key) use ($row) {
                    $v = $row[$key] ?? '';

                    return is_string($v) && preg_match('/^[=+\-@\t\r|]/', $v) ? "'".$v : $v;
                }, array_keys($columns)));
            }
            fclose($out);
        }, "equipment-{$type}-{$from->format('Ymd')}-{$to->format('Ymd')}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function params(Request $request): array
    {
        $request->validate([
            'type' => 'nullable|in:'.implode(',', array_keys(self::COLUMNS)),
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'standard_hours' => 'nullable|numeric|min:1|max:24',
        ]);
        $from = Carbon::parse($request->input('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->startOfDay();
        $hours = (float) $request->input('standard_hours', app(TenantManager::class)->getCompany()?->setting('equipment_standard_hours', 10) ?? 10);

        return [$request->input('type', 'profitability'), $from, $to, $hours];
    }

    protected function run(EquipmentReports $reports, string $type, Carbon $from, Carbon $to, float $hours): array
    {
        return match ($type) {
            'utilization' => $reports->utilization($from, $to, $hours),
            'compliance' => $reports->compliance($from, $to),
            'parts' => $reports->parts($from, $to),
            default => $reports->profitability($from, $to),
        };
    }
}

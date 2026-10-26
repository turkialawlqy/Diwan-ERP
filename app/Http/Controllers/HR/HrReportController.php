<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Services\Accounting\AccountMap;
use App\Services\Settings\CompanyPolicy;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Payroll compliance reports: the monthly GOSI contribution schedule
 * (reconciled to the GOSI payable account) and an indicative Saudization
 * (Nitaqat) ratio by branch and department.
 */
class HrReportController extends Controller
{
    public function gosi(Request $request)
    {
        $month = $this->month($request);
        $report = $this->gosiReport($month);

        if ($request->query('export') === 'csv') {
            abort_unless($request->user()->can('hr.reports.export'), 403);

            return $this->csv("GOSI-{$month->format('Y-m')}.csv",
                ['GOSI No', 'ID', 'Name', 'Nationality', 'Contributory Wage', 'Employee Share', 'Employer Share', 'Total'],
                array_map(fn ($r) => [$r['gosi_number'], $r['national_id'], $r['name'], $r['nationality'], $r['base'], $r['employee'], $r['employer'], $r['total']], $report['rows']));
        }

        return Inertia::render('HR/Reports/Gosi', $report + [
            'filters' => ['month' => $month->format('Y-m')],
            'establishment' => CompanyPolicy::get('hr.wps.gosi_establishment_id', ''),
        ]);
    }

    public function saudization(Request $request)
    {
        $date = Carbon::parse($request->input('date') ?: today())->endOfDay();
        $report = $this->saudizationReport($date);

        if ($request->query('export') === 'csv') {
            abort_unless($request->user()->can('hr.reports.export'), 403);

            return $this->csv("saudization-{$date->format('Y-m-d')}.csv",
                ['Branch', 'Department', 'Headcount', 'Saudis', 'Saudis Counted', 'Non-Saudis', 'Saudization %'],
                array_map(fn ($g) => [$g['branch'], $g['department'], $g['headcount'], $g['saudis'], $g['counted'], $g['non_saudis'], $g['ratio']], $report['groups']));
        }

        return Inertia::render('HR/Reports/Saudization', $report + ['filters' => ['date' => $date->toDateString()]]);
    }

    /**
     * Contributions of the month's approved / paid payrolls, per employee,
     * with the GOSI payable those payrolls credited in the ledger.
     */
    public function gosiReport(Carbon $month): array
    {
        $payrolls = Payroll::where('year', $month->year)->where('month', $month->month)->whereIn('status', ['approved', 'paid'])->pluck('id');
        $items = PayrollItem::with('employee:id,name,national_id,nationality,gosi_number')->whereIn('payroll_id', $payrolls)->get();

        $rows = $items->filter(fn ($i) => (float) $i->gosi_employee + (float) $i->gosi_employer > 0)->map(fn ($i) => [
            'employee_id' => $i->employee_id,
            'gosi_number' => (string) $i->employee?->gosi_number,
            'national_id' => (string) $i->employee?->national_id,
            'name' => (string) $i->employee?->name,
            'nationality' => strtoupper((string) $i->employee?->nationality) === 'SA' ? 'سعودي' : 'غير سعودي',
            'base' => round((float) ($i->breakdown['gosi_base'] ?? ((float) $i->basic_salary + (float) $i->housing_allowance)), 2),
            'employee' => round((float) $i->gosi_employee, 2),
            'employer' => round((float) $i->gosi_employer, 2),
            'total' => round((float) $i->gosi_employee + (float) $i->gosi_employer, 2),
        ])->sortBy('name')->values()->all();

        $totals = [
            'base' => round(array_sum(array_column($rows, 'base')), 2),
            'employee' => round(array_sum(array_column($rows, 'employee')), 2),
            'employer' => round(array_sum(array_column($rows, 'employer')), 2),
            'total' => round(array_sum(array_column($rows, 'total')), 2),
        ];

        $payableId = app(AccountMap::class)->find('gosi_payable');
        $ledger = $payableId ? round((float) DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', $this->companyId())
            ->where('e.source_type', (new Payroll)->getMorphClass())->whereIn('e.source_id', $payrolls)
            ->whereNull('e.reversal_of_id')->where('e.status', JournalEntry::STATUS_POSTED)
            ->where('l.account_id', $payableId)->sum('l.credit'), 2) : 0.0;

        return [
            'rows' => $rows,
            'totals' => $totals,
            'ledger' => $ledger,
            'difference' => round($totals['total'] - $ledger, 2),
            'missing_numbers' => count(array_filter($rows, fn ($r) => $r['gosi_number'] === '')),
        ];
    }

    /**
     * Active employees at the date by branch and department. A Saudi counts
     * fully at or above the full wage, half from the half wage, and not at
     * all below (basic + housing, see config hr.saudization).
     */
    public function saudizationReport(Carbon $date): array
    {
        $full = (float) CompanyPolicy::get('hr.saudization.full_wage', 4000);
        $half = (float) CompanyPolicy::get('hr.saudization.half_wage', 3000);

        $employees = Employee::with(['branch:id,name_ar', 'departmentRef:id,name'])
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $date))
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>', $date))
            ->where(fn ($q) => $q->where('status', '!=', 'terminated')->orWhereNotNull('termination_date'))
            ->get();

        $weight = function (Employee $e) use ($full, $half) {
            if (! $e->isSaudi()) {
                return 0.0;
            }
            $wage = (float) $e->basic_salary + (float) $e->housing_allowance;

            return $wage >= $full ? 1.0 : ($wage >= $half ? 0.5 : 0.0);
        };

        $summarise = function ($group) use ($weight) {
            $saudis = $group->filter->isSaudi();
            $counted = round($saudis->sum($weight), 1);
            $headcount = $group->count();

            return [
                'headcount' => $headcount,
                'saudis' => $saudis->count(),
                'counted' => $counted,
                'non_saudis' => $headcount - $saudis->count(),
                'ratio' => $headcount ? round($counted / $headcount * 100, 1) : 0.0,
                'below_half' => $saudis->filter(fn ($e) => $weight($e) === 0.0)->count(),
            ];
        };

        $groups = $employees->groupBy(fn ($e) => ($e->branch?->name_ar ?? 'المنشأة').'|'.($e->departmentRef?->name ?? $e->department ?? 'بدون قسم'))
            ->map(function ($group, $key) use ($summarise) {
                [$branch, $department] = explode('|', $key, 2);

                return ['branch' => $branch, 'department' => $department] + $summarise($group);
            })->sortBy(['branch', 'department'])->values()->all();

        return [
            'groups' => $groups,
            'total' => $summarise($employees),
            'thresholds' => ['full' => $full, 'half' => $half],
        ];
    }

    protected function month(Request $request): Carbon
    {
        $value = (string) $request->input('month', '');

        return preg_match('/^\d{4}-\d{2}$/', $value) ? Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfDay() : today()->startOfMonth();
    }

    protected function companyId(): ?int
    {
        return app(TenantManager::class)->getTenantId();
    }

    protected function csv(string $name, array $header, array $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r|]/', $v) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

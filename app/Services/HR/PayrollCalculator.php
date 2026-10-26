<?php

namespace App\Services\HR;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\EmployeeSalaryChange;
use App\Models\LeaveRequest;
use App\Services\Settings\CompanyPolicy;
use Illuminate\Support\Carbon;

/**
 * One employee's pay for one month under the Saudi Labour Law:
 *  - 30-day salary month; joiners / leavers prorated by days in service;
 *  - absence and unpaid leave deducted at the daily wage;
 *  - sick leave paid in tiers (full / 75% / unpaid) across the year;
 *  - overtime = hours x (hourly wage + 50% of hourly basic);
 *  - GOSI on basic + housing (+ flagged allowances), within the legal bounds;
 *  - loan / penalty deductions capped at half of the wage.
 */
class PayrollCalculator
{
    public function __construct(protected LeaveService $leaves) {}

    public function calculate(Employee $employee, int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = $from->copy()->endOfMonth()->startOfDay();
        $monthDays = (int) CompanyPolicy::get('hr.month_days', 30);
        $calendarDays = $from->daysInMonth;

        // Days in service this month, scaled to the 30-day salary month.
        $serviceStart = $employee->hire_date && $employee->hire_date->gt($from) ? $employee->hire_date->copy() : $from->copy();
        $serviceEnd = $employee->termination_date && $employee->termination_date->lt($to) ? $employee->termination_date->copy() : $to->copy();
        $inService = $serviceEnd->lt($serviceStart) ? 0 : (int) round($serviceStart->diffInDays($serviceEnd)) + 1;
        $daysWorked = $inService >= $calendarDays ? $monthDays : (int) round($inService * $monthDays / $calendarDays);
        $ratio = $daysWorked / $monthDays;

        $payItems = EmployeePayItem::where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $to))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from))->get();
        $extraAllowances = (float) $payItems->where('kind', 'allowance')->sum('amount');
        $fixedDeductions = (float) $payItems->where('kind', 'deduction')->sum('amount');

        // Pay in effect on each day of the month (salary changes take effect on their date).
        $changes = EmployeeSalaryChange::where('employee_id', $employee->id)->whereDate('effective_date', '>', $from)
            ->orderBy('effective_date')->orderBy('id')->get();
        $payOn = function (Carbon $day) use ($employee, $changes) {
            $next = $changes->first(fn ($c) => $c->effective_date->gt($day));
            $source = $next ? $next->previous : $employee->only(EmployeeSalaryChange::FIELDS);

            return array_map(fn ($f) => (float) ($source[$f] ?? 0), array_combine(EmployeeSalaryChange::FIELDS, EmployeeSalaryChange::FIELDS));
        };

        $current = $payOn($to);
        $basic = $current['basic_salary'];
        $housing = $current['housing_allowance'];
        $transport = $current['transport_allowance'];
        $other = $current['other_allowances'] + $extraAllowances;
        $monthlyWage = $basic + $housing + $transport + $other;
        $dailyWage = $monthlyWage / $monthDays;

        // Absence (attendance) and unpaid leave. A day marked absent inside
        // approved leave is already settled by the leave's pay rule.
        $leaveRanges = LeaveRequest::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get(['start_date', 'end_date']);
        $absentDays = AttendanceRecord::where('employee_id', $employee->id)->where('status', 'absent')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->pluck('date')
            ->reject(fn ($date) => $leaveRanges->contains(fn ($l) => Carbon::parse($date)->betweenIncluded($l->start_date->copy()->startOfDay(), $l->end_date->copy()->startOfDay())))
            ->count();
        $unpaidLeave = $this->leaves->daysInPeriod($employee, 'unpaid', $from, $to);
        $sickDeductionDays = $this->sickDeductionDays($employee, $from, $to);
        $absence = round($dailyWage * ($absentDays + $unpaidLeave + $sickDeductionDays), 2);

        // Lateness, when the company's work regulation deducts it: minutes beyond the monthly allowance at the minute wage.
        $lateMinutes = (int) AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('late_minutes');
        $lateDeducted = 0;
        if (CompanyPolicy::get('hr.late_deduction.enabled', false)) {
            $lateDeducted = max(0, $lateMinutes - (int) CompanyPolicy::get('hr.late_deduction.monthly_grace_minutes', 0));
            $absence = round($absence + $lateDeducted * $monthlyWage / ($monthDays * (float) CompanyPolicy::get('hr.hours_per_day', 8) * 60), 2);
        }

        // Overtime: attendance minutes + approved overtime hours.
        $adjustments = EmployeeAdjustment::where('employee_id', $employee->id)->where('year', $year)->where('month', $month)->get();
        $overtimeHours = (float) $adjustments->where('type', 'overtime_hours')->sum('amount')
            + AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('overtime_minutes') / 60;
        $hoursPerMonth = $monthDays * (int) CompanyPolicy::get('hr.hours_per_day', 8);
        $overtime = round($overtimeHours * ($monthlyWage / $hoursPerMonth + CompanyPolicy::get('hr.overtime_premium', 0.5) * $basic / $hoursPerMonth), 2);

        $bonuses = round((float) $adjustments->whereIn('type', ['bonus', 'commission'])->sum('amount'), 2);
        $penalties = round((float) $adjustments->whereIn('type', ['penalty', 'deduction'])->sum('amount') + $fixedDeductions, 2);
        $disciplinary = round((float) $adjustments->where('type', 'penalty')->sum('amount'), 2);

        $earned = ['basic_salary' => $basic * $ratio, 'housing_allowance' => $housing * $ratio, 'transport_allowance' => $transport * $ratio, 'other_allowances' => $other * $ratio];
        $midMonth = $changes->filter(fn ($c) => $c->effective_date->lte($to));
        if ($midMonth->isNotEmpty() && $inService > 0) {
            // Each stretch of the month at the pay then in effect, by calendar days in service.
            $earned = array_fill_keys(EmployeeSalaryChange::FIELDS, 0.0);
            $bounds = $midMonth->map(fn ($c) => $c->effective_date->copy()->startOfDay())->unique(fn ($d) => $d->toDateString())->values()->prepend($from->copy())->push($to->copy()->addDay());
            for ($i = 0; $i < $bounds->count() - 1; $i++) {
                $start = $bounds[$i]->max($serviceStart);
                $end = $bounds[$i + 1]->copy()->subDay()->min($serviceEnd);
                if ($end->lt($start)) {
                    continue;
                }
                $days = (int) round($start->diffInDays($end)) + 1;
                foreach ($payOn($start) as $field => $amount) {
                    $earned[$field] += $amount * $days / $calendarDays;
                }
            }
            $earned['other_allowances'] += $extraAllowances * $ratio;
        }
        $earnedBasic = round($earned['basic_salary'], 2);
        $earnedHousing = round($earned['housing_allowance'], 2);
        $earnedTransport = round($earned['transport_allowance'], 2);
        $earnedOther = round($earned['other_allowances'], 2);
        // Absence can take the month's wage to nil, never below.
        $absence = min($absence, round($earnedBasic + $earnedHousing + $earnedTransport + $earnedOther, 2));
        $gross = round($earnedBasic + $earnedHousing + $earnedTransport + $earnedOther + $overtime + $bonuses - $absence, 2);

        [$gosiEmployee, $gosiEmployer, $gosiBase] = $this->gosi($employee, $basic + $housing + (float) $payItems->where('kind', 'allowance')->where('gosi_applicable', true)->sum('amount'), $ratio, $to);

        // Loans and penalties within half the wage (Art. 92).
        $cap = max(0, round($gross * CompanyPolicy::get('hr.max_deduction_ratio', 0.5), 2));
        $penalties = min($penalties, $cap);
        // Disciplinary fines are not the employer's income (Art. 72): they are held for the workers' fund.
        $disciplinary = min($disciplinary, $penalties);
        $loanDue = $this->loanInstallments($employee, $to);
        $loanDeduction = round(min($loanDue, max(0, $cap - $penalties)), 2);

        $net = round($gross - $gosiEmployee - $loanDeduction - $penalties, 2);

        return [
            'employee_id' => $employee->id,
            'days_worked' => $daysWorked,
            'basic_salary' => $earnedBasic,
            'housing_allowance' => $earnedHousing,
            'transport_allowance' => $earnedTransport,
            'other_allowances' => $earnedOther,
            'allowances' => round($earnedHousing + $earnedTransport + $earnedOther, 2),
            'overtime' => $overtime,
            'bonuses' => $bonuses,
            'absence_deduction' => $absence,
            'loan_deduction' => $loanDeduction,
            'other_deductions' => $penalties,
            'gosi_employee' => $gosiEmployee,
            'gosi_employer' => $gosiEmployer,
            'gross_salary' => $gross,
            'deductions' => round($gosiEmployee + $loanDeduction + $penalties, 2),
            'net_salary' => $net,
            'cost_center_id' => $employee->cost_center_id ?? $employee->departmentRef?->cost_center_id,
            'breakdown' => [
                'absent_days' => $absentDays,
                'unpaid_leave_days' => $unpaidLeave,
                'sick_deduction_days' => $sickDeductionDays,
                'late_minutes' => $lateMinutes,
                'late_deducted_minutes' => $lateDeducted,
                'overtime_hours' => round($overtimeHours, 2),
                'gosi_base' => $gosiBase,
                'loan_due' => $loanDue,
                'deduction_cap' => $cap,
                'penalties' => $disciplinary,
            ],
        ];
    }

    /**
     * GOSI contributions on the contribution wage (bounded), prorated for partial months.
     *
     * @return array{0: float, 1: float, 2: float} [employee, employer, base]
     */
    public function gosi(Employee $employee, float $wage, float $ratio, Carbon $periodEnd): array
    {
        if (! $employee->gosi_registered) {
            return [0.0, 0.0, 0.0];
        }

        $cfg = CompanyPolicy::get('hr.gosi');
        $base = min(max($wage, $employee->isSaudi() ? $cfg['min_wage'] : 0), $cfg['max_wage']) * $ratio;

        if (! $employee->isSaudi()) {
            return [round($base * $cfg['non_saudi_employee_rate'], 2), round($base * $cfg['non_saudi_employer_rate'], 2), round($base, 2)];
        }

        $employeeRate = $cfg['saudi_employee_rate'];
        $employerRate = $cfg['saudi_employer_rate'];

        // New entrants under the 2024 law: higher annuity share, phased in each July.
        if ($employee->gosi_join_date && $employee->gosi_join_date->gte(Carbon::parse($cfg['new_law_start']))) {
            $baseAnnuity = (float) ($cfg['base_annuity_rate'] ?? 0.09);
            $annuity = $baseAnnuity;
            foreach ($cfg['new_entrant_annuity'] as $from => $rate) {
                if ($periodEnd->gte(Carbon::parse($from))) {
                    $annuity = $rate;
                }
            }
            $employeeRate += $annuity - $baseAnnuity;
            $employerRate += $annuity - $baseAnnuity;
        }

        return [round($base * $employeeRate, 2), round($base * $employerRate, 2), round($base, 2)];
    }

    /**
     * Sick days in this month paid at a reduced rate, expressed as days of
     * deduction (the 75% tier counts as a quarter day each).
     */
    protected function sickDeductionDays(Employee $employee, Carbon $from, Carbon $to): float
    {
        $yearStart = $from->copy()->startOfYear();
        $before = $this->leaves->daysInPeriod($employee, 'sick_tiers', $yearStart, $from->copy()->subDay());
        $now = $this->leaves->daysInPeriod($employee, 'sick_tiers', $from, $to);
        if ($now <= 0) {
            return 0.0;
        }

        $deduction = 0.0;
        $cursor = $before;
        $remaining = $now;
        $tierEnd = 0;
        foreach (CompanyPolicy::get('hr.sick_tiers') as [$length, $pay]) {
            $tierEnd += $length;
            $inTier = max(0, min($remaining, $tierEnd - $cursor));
            $deduction += $inTier * (1 - $pay);
            $cursor += $inTier;
            $remaining -= $inTier;
        }

        return $deduction + $remaining; // beyond the last tier: unpaid
    }

    protected function loanInstallments(Employee $employee, Carbon $periodEnd): float
    {
        return round(EmployeeLoan::where('employee_id', $employee->id)->where('status', 'active')
            ->whereDate('first_deduction_month', '<=', $periodEnd)->get()
            ->sum(fn ($loan) => min((float) $loan->monthly_installment, (float) $loan->amount - (float) $loan->repaid)), 2);
    }
}

<?php

namespace App\Services\HR;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\WorkShift;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Daily attendance against the employee's shift: worked, late and overtime
 * minutes. Manual entry or CSV import (employee number, date, in, out) from
 * fingerprint devices.
 */
class AttendanceService
{
    public function record(Employee $employee, string $date, ?string $in, ?string $out, string $source = 'manual', ?string $status = null, ?string $notes = null): AttendanceRecord
    {
        $shift = $employee->shift ?? WorkShift::where('is_default', true)->first();
        $day = Carbon::parse($date);
        $metrics = ['worked_minutes' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0];

        if (! $status) {
            $status = $in ? 'present' : ($shift && ! in_array($day->isoWeekday(), $shift->work_days ?? [], true) ? 'weekend' : (PublicHoliday::covers($day) ? 'holiday' : 'absent'));
        }

        if ($in && $out) {
            $start = Carbon::parse($date.' '.$in);
            $end = Carbon::parse($date.' '.$out);
            if ($end->lt($start)) {
                $end->addDay(); // night shift
            }
            $worked = (int) round($start->diffInMinutes($end)) - ($shift?->break_minutes ?? 0);
            $metrics['worked_minutes'] = max(0, $worked);

            if ($shift) {
                $shiftStart = Carbon::parse($date.' '.$shift->start_time);
                $shiftEnd = Carbon::parse($date.' '.$shift->end_time);
                if ($shiftEnd->lt($shiftStart)) {
                    $shiftEnd->addDay();
                }
                $scheduled = (int) round($shiftStart->diffInMinutes($shiftEnd)) - $shift->break_minutes;
                $late = (int) round($shiftStart->diffInMinutes($start, false));
                $metrics['late_minutes'] = $late > $shift->grace_minutes ? $late : 0;
                $isWorkDay = in_array($day->isoWeekday(), $shift->work_days ?? [], true);
                $metrics['overtime_minutes'] = max(0, $isWorkDay ? $metrics['worked_minutes'] - $scheduled : $metrics['worked_minutes']);
            }
        }

        return AttendanceRecord::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $day->toDateString()],
            $metrics + ['check_in' => $in, 'check_out' => $out, 'status' => $status, 'source' => $source, 'notes' => $notes],
        );
    }

    /**
     * Import "employee_number,date,check_in,check_out" rows.
     *
     * @return array{imported: int, errors: array}
     */
    public function import(string $csv): array
    {
        $imported = 0;
        $errors = [];
        $employees = Employee::whereNotNull('employee_number')->pluck('id', 'employee_number');

        foreach (preg_split('/\r?\n/', trim($csv)) as $i => $line) {
            $cells = array_map('trim', str_getcsv($line));
            if ($i === 0 && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $cells[1] ?? '')) {
                continue; // header
            }
            [$number, $date, $in, $out] = array_pad($cells, 4, null);
            $employeeId = $employees[$number] ?? null;
            if (! $employeeId || ! strtotime((string) $date)) {
                $errors[] = 'سطر '.($i + 1).': موظف أو تاريخ غير صحيح';

                continue;
            }
            $this->record(Employee::find($employeeId), $date, $in ?: null, $out ?: null, 'import');
            $imported++;
        }

        if ($imported === 0 && $errors) {
            throw ValidationException::withMessages(['file' => implode(' | ', array_slice($errors, 0, 5))]);
        }

        return compact('imported', 'errors');
    }
}

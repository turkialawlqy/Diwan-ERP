<?php

namespace App\Services\HR;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Services\Settings\CompanyPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function ensureTypes(Company $company): void
    {
        if (LeaveType::where('company_id', $company->id)->exists()) {
            return;
        }
        foreach (config('hr.default_leave_types') as $type) {
            LeaveType::create($type + ['company_id' => $company->id]);
        }
    }

    /**
     * Annual leave accrued since hire (21 days/year, 30 after five years),
     * plus the opening balance, less approved annual leave taken.
     */
    public function balance(Employee $employee, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $accrued = $this->accrued($employee, $asOf);
        $used = (float) LeaveRequest::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereHas('type', fn ($q) => $q->where('deducts_balance', true))
            ->whereDate('start_date', '<=', $asOf)->sum('days');

        return [
            'accrued' => round($accrued, 2),
            'opening' => (float) $employee->leave_opening_balance,
            'used' => round($used, 2),
            'balance' => round($accrued + (float) $employee->leave_opening_balance - $used, 2),
        ];
    }

    public function accrued(Employee $employee, Carbon $asOf): float
    {
        if (! $employee->hire_date || $employee->hire_date->gt($asOf)) {
            return 0.0;
        }

        $rules = CompanyPolicy::get('hr.annual_leave');
        $base = max((int) $employee->annual_leave_days, $rules['days']);
        $seniorFrom = $employee->hire_date->copy()->addYears($rules['senior_after_years']);
        $end = $employee->termination_date && $employee->termination_date->lt($asOf) ? $employee->termination_date : $asOf;

        $juniorDays = $employee->hire_date->diffInDays($end->lt($seniorFrom) ? $end : $seniorFrom);
        $seniorDays = $end->gt($seniorFrom) ? $seniorFrom->diffInDays($end) : 0;

        return $juniorDays * $base / 365 + $seniorDays * max($base, $rules['senior_days']) / 365;
    }

    public function request(Employee $employee, array $data): LeaveRequest
    {
        $type = LeaveType::findOrFail($data['leave_type_id']);
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $days = (int) round($start->diffInDays($end)) + 1;
        // Official holidays inside annual leave are not taken from the balance (Art. 112).
        if ($type->deducts_balance) {
            $days -= PublicHoliday::daysBetween($start, $end);
            if ($days <= 0) {
                throw ValidationException::withMessages(['start_date' => 'الفترة كلها عطلة رسمية؛ لا حاجة لطلب إجازة.']);
            }
        }

        $overlap = LeaveRequest::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => 'توجد إجازة أخرى في نفس الفترة.']);
        }

        if ($type->deducts_balance && $days > $this->balance($employee, $start)['balance'] + 0.001 && ! ($data['allow_negative'] ?? false)) {
            throw ValidationException::withMessages(['end_date' => 'رصيد الإجازات السنوية غير كافٍ ('.$this->balance($employee, $start)['balance'].' يوم).']);
        }

        if ($type->days_per_year > 0 && ! $type->deducts_balance) {
            $taken = (float) LeaveRequest::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('status', 'approved')
                ->whereYear('start_date', $start->year)->sum('days');
            if ($taken + $days > $type->days_per_year) {
                throw ValidationException::withMessages(['end_date' => "تتجاوز المدة المسموحة لهذا النوع ({$type->days_per_year} يوم سنوياً)."]);
            }
        }

        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'status' => 'pending',
            'reason' => $data['reason'] ?? null,
        ]);
    }

    public function decide(LeaveRequest $request, bool $approve, ?string $note = null): void
    {
        DB::transaction(function () use ($request, $approve, $note) {
            $request = LeaveRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['leave' => 'الطلب ليس قيد الانتظار.']);
            }
            EmployeeFinanceService::assertNotOwnRequest($request->employee);
            // Another request may have used the balance since this one was filed.
            if ($approve && $request->type?->deducts_balance) {
                $available = $this->balance($request->employee, Carbon::parse($request->start_date))['balance'];
                if ((float) $request->days > $available + 0.001) {
                    throw ValidationException::withMessages(['leave' => "رصيد الإجازات السنوية لا يكفي لهذا الطلب (المتاح {$available} يوم)."]);
                }
            }
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'decision_note' => $note, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    /**
     * Days of approved leave of a pay rule falling in [from, to].
     */
    public function daysInPeriod(Employee $employee, string $payRule, Carbon $from, Carbon $to): float
    {
        return LeaveRequest::with('type')->where('employee_id', $employee->id)->where('status', 'approved')
            ->whereHas('type', fn ($q) => $q->where('pay_rule', $payRule))
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get()
            ->sum(fn ($l) => Carbon::parse(max($l->start_date->toDateString(), $from->toDateString()))
                ->diffInDays(Carbon::parse(min($l->end_date->toDateString(), $to->toDateString()))) + 1);
    }
}

<?php

namespace App\Services\HR;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
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
        // Half a day: one day only, counted as 0.5.
        $half = ! empty($data['half_day']);
        if ($half && ! $start->isSameDay($end)) {
            throw ValidationException::withMessages(['half_day' => 'نصف اليوم لا يكون إلا ليوم واحد.']);
        }
        // Official holidays inside annual leave are not taken from the balance (Art. 112).
        if ($type->deducts_balance) {
            $days -= PublicHoliday::daysBetween($start, $end);
            if ($days <= 0) {
                throw ValidationException::withMessages(['start_date' => 'الفترة كلها عطلة رسمية؛ لا حاجة لطلب إجازة.']);
            }
        }

        if ($half) {
            $days = 0.5;
        }

        // Hajj leave is granted once in the whole service, after two years with the employer (Labor Law Art. 114).
        if ($type->code === 'hajj') {
            if (! $employee->hire_date || $employee->hire_date->copy()->addYears(2)->gt($start)) {
                throw ValidationException::withMessages(['leave_type_id' => 'إجازة الحج تُمنح بعد سنتين من الخدمة.']);
            }
            if (LeaveRequest::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                throw ValidationException::withMessages(['leave_type_id' => 'إجازة الحج تُمنح مرة واحدة طوال الخدمة.']);
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
            // The balance is the employee's: two approvals for one employee go one after the other. Locked before
            // any plain read, so that the balance below is read after the other approval has been committed.
            Employee::whereKey($request->employee_id)->lockForUpdate()->first();
            EmployeeFinanceService::assertNotOwnRequest($request->employee);
            // Leave inside a month whose payroll is already approved would change pay that was settled.
            if ($approve) {
                for ($m = Carbon::parse($request->start_date)->startOfMonth(); $m->lte(Carbon::parse($request->end_date)); $m->addMonth()) {
                    if (Payroll::where('year', $m->year)->where('month', $m->month)->where('status', '!=', 'draft')->exists()) {
                        throw ValidationException::withMessages(['leave' => 'مسير شهر '.$m->format('m/Y').' معتمد؛ لا تُعتمد إجازة داخله قبل إعادته إلى مسودة.']);
                    }
                }
            }
            // Another request may have used the balance since this one was filed.
            if ($approve && $request->type?->deducts_balance) {
                $available = $this->availableFor($request);
                if ((float) $request->days > $available + 0.001) {
                    throw ValidationException::withMessages(['leave' => "رصيد الإجازات السنوية لا يكفي لهذا الطلب (المتاح {$available} يوم)."]);
                }
            }
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'decision_note' => $note, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    /**
     * Days this request can take: the balance on its first day, or on the first day of a leave already approved
     * after it, whichever is lower. That later leave was approved counting on days this one would now use.
     */
    protected function availableFor(LeaveRequest $request): float
    {
        $start = Carbon::parse($request->start_date);
        $later = LeaveRequest::where('employee_id', $request->employee_id)->where('status', 'approved')
            ->whereHas('type', fn ($q) => $q->where('deducts_balance', true))
            ->whereDate('start_date', '>', $start)->pluck('start_date');

        return collect([$start, ...$later])->map(fn ($day) => $this->balance($request->employee, Carbon::parse($day))['balance'])->min();
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

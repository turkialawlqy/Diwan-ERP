<?php

namespace App\Services\HR;

use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EndOfServiceSettlement;
use App\Models\PayrollItem;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\Settings\CompanyPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee loans and end-of-service (Art. 84/85) with the monthly provision.
 *
 *   Loan disbursed        Dr Employee advances      Cr Cash / Bank
 *   EOS provision         Dr End of service expense Cr End of service provision
 *   Final settlement      Dr EOS provision (+ expense for any shortfall), Salaries expense (leave pay, dues)
 *                         Cr Employee advances (loans), Salaries payable (net)
 *   Settlement paid       Dr Salaries payable       Cr Bank
 */
class EmployeeFinanceService
{
    public const REASONS = [
        'resignation' => 'استقالة (م 85)',
        'termination' => 'إنهاء من صاحب العمل',
        'contract_end' => 'انتهاء العقد',
        'article_80' => 'فصل وفق المادة 80 (بدون مكافأة)',
        'article_81' => 'ترك العمل وفق المادة 81',
        'retirement' => 'التقاعد',
        'death' => 'الوفاة أو العجز',
    ];

    public function __construct(
        protected PostingService $posting,
        protected DocumentPoster $poster,
        protected AccountMap $accounts,
        protected LeaveService $leaves,
    ) {}

    /** Nobody approves their own leave or loan. */
    public static function assertNotOwnRequest(?Employee $employee): void
    {
        if ($employee?->user_id && (int) $employee->user_id === (int) auth()->id()) {
            throw ValidationException::withMessages(['approval' => 'لا يمكنك البت في طلبك الشخصي؛ يعتمده مسؤول آخر.']);
        }
    }

    // ----- Loans -----

    public function approveLoan(EmployeeLoan $loan, ?int $bankAccountId): void
    {
        DB::transaction(function () use ($loan, $bankAccountId) {
            $loan = EmployeeLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            self::assertNotOwnRequest($loan->employee);
            if ($loan->status !== 'pending') {
                throw ValidationException::withMessages(['loan' => 'السلفة ليست قيد الانتظار.']);
            }

            $this->posting->post([
                'date' => $loan->date,
                'reference' => 'LOAN-'.$loan->id,
                'description' => 'صرف سلفة للموظف '.$loan->employee->name,
                'source' => $loan,
                'lines' => [
                    ['role' => 'employee_advances', 'debit' => (float) $loan->amount, 'party_type' => 'employee', 'party_id' => $loan->employee_id],
                    ['account_id' => $this->poster->cashAccount($bankAccountId, 'bank_transfer'), 'credit' => (float) $loan->amount],
                ],
            ]);

            $loan->update(['status' => 'active', 'bank_account_id' => $bankAccountId, 'approved_by' => auth()->id()]);
        });
    }

    // ----- End of service -----

    /**
     * Gratuity for an employee leaving on $date for $reason.
     */
    public function gratuity(Employee $employee, Carbon $date, string $reason): array
    {
        $rules = CompanyPolicy::get('hr.end_of_service');
        // Calendar years of service (leap-year safe), fractions pro rata.
        $years = $employee->hire_date ? max(0, (float) $employee->hire_date->diffInYears($date)) : 0;
        // Unpaid leave is not service when it exceeds twenty days.
        $unpaid = $employee->hire_date ? $this->leaves->daysInPeriod($employee, 'unpaid', $employee->hire_date->copy(), $date->copy()) : 0;
        if ($unpaid > 20) {
            $years = max(0, $years - $unpaid / 365);
        }
        $wage = (float) $employee->basic_salary + (float) $employee->housing_allowance + (float) $employee->transport_allowance + (float) $employee->other_allowances;

        $full = $wage * $rules['first_rate'] * min($years, $rules['first_years'])
            + $wage * $rules['later_rate'] * max(0, $years - $rules['first_years']);

        $share = match ($reason) {
            'article_80' => 0.0,
            'resignation' => $this->resignationShare($years),
            default => 1.0,
        };

        return ['years' => round($years, 4), 'wage' => round($wage, 2), 'full' => round($full, 2), 'amount' => round($full * $share, 2), 'share' => $share];
    }

    protected function resignationShare(float $years): float
    {
        foreach (CompanyPolicy::get('hr.end_of_service.resignation') as [$below, $share]) {
            if ($years < $below) {
                return $share;
            }
        }

        return 1.0;
    }

    /**
     * Post the change in the end-of-service liability of all active employees.
     */
    public function accrueProvision(Carbon $asOf): float
    {
        return DB::transaction(function () use ($asOf) {
            $liability = Employee::where('status', '!=', 'terminated')->whereNotNull('hire_date')->get()
                ->sum(fn ($e) => $this->gratuity($e, $asOf, 'termination')['full']);
            $current = (float) Account::whereKey($this->accounts->id('end_of_service_provision'))->value('balance');
            $difference = round($liability - $current, 2);

            if (abs($difference) >= 0.01) {
                $this->posting->post([
                    'date' => $asOf->toDateString(),
                    'description' => 'مخصص مكافأة نهاية الخدمة حتى '.$asOf->toDateString(),
                    'source_type' => 'eos_provision',
                    'lines' => $difference > 0
                        ? [['role' => 'end_of_service_expense', 'debit' => $difference], ['role' => 'end_of_service_provision', 'credit' => $difference]]
                        : [['role' => 'end_of_service_provision', 'debit' => -$difference], ['role' => 'end_of_service_expense', 'credit' => -$difference]],
                ]);
            }

            return $difference;
        });
    }

    /**
     * Post the change in the accrued annual leave liability: unused leave
     * days of every active employee at the daily wage.
     */
    public function accrueLeaveProvision(Carbon $asOf): float
    {
        return DB::transaction(function () use ($asOf) {
            $days = CompanyPolicy::get('hr.month_days', 30);
            $liability = round(Employee::where('status', '!=', 'terminated')->whereNotNull('hire_date')->get()
                ->sum(fn ($e) => max(0, $this->leaves->balance($e, $asOf)['balance']) * $this->gratuity($e, $asOf, 'termination')['wage'] / $days), 2);
            $current = (float) Account::whereKey($this->accounts->id('leave_provision'))->value('balance');
            $difference = round($liability - $current, 2);

            if (abs($difference) >= 0.01) {
                $this->posting->post([
                    'date' => $asOf->toDateString(),
                    'description' => 'مخصص الإجازات السنوية حتى '.$asOf->toDateString(),
                    'source_type' => 'leave_provision',
                    'lines' => $difference > 0
                        ? [['role' => 'leave_expense', 'debit' => $difference], ['role' => 'leave_provision', 'credit' => $difference]]
                        : [['role' => 'leave_provision', 'debit' => -$difference], ['role' => 'leave_expense', 'credit' => -$difference]],
                ]);
            }

            return $difference;
        });
    }

    /**
     * End-of-service due within twelve months (limited contracts ending, or
     * reaching retirement age, in the next year): the current portion of the
     * provision on the balance sheet.
     */
    public function eosCurrentPortion(Carbon $asOf): float
    {
        $horizon = $asOf->copy()->addYear();

        return round(Employee::where('status', '!=', 'terminated')->whereNotNull('hire_date')->get()
            ->filter(fn ($e) => ($e->contract_type === 'limited' && $e->contract_end && $e->contract_end->lte($horizon))
                || ($e->birth_date && $e->birth_date->copy()->addYears(60)->lte($horizon)))
            ->sum(fn ($e) => $this->gratuity($e, $asOf, 'termination')['full']), 2);
    }

    /**
     * Draft final settlement: gratuity, leave encashment, unpaid salary days,
     * less outstanding loans.
     */
    public function draftSettlement(Employee $employee, array $data): EndOfServiceSettlement
    {
        if (EndOfServiceSettlement::where('employee_id', $employee->id)->whereIn('status', ['draft', 'approved', 'paid'])->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'توجد مخالصة لهذا الموظف.']);
        }

        $date = Carbon::parse($data['termination_date']);
        if ((float) ($data['salary_days'] ?? 0) > 0 && PayrollItem::where('employee_id', $employee->id)
            ->whereHas('payroll', fn ($q) => $q->where('year', $date->year)->where('month', $date->month)->whereIn('status', ['approved', 'paid']))->exists()) {
            throw ValidationException::withMessages(['salary_days' => 'راتب شهر الإنهاء مدفوع في مسير معتمد؛ اترك أيام الراتب صفراً حتى لا تُصرف مرتين.']);
        }
        $gratuity = $this->gratuity($employee, $date, $data['reason']);
        $daily = $gratuity['wage'] / CompanyPolicy::get('hr.month_days', 30);
        $leaveDays = max(0, $this->leaves->balance($employee, $date)['balance']);
        $loans = (float) EmployeeLoan::where('employee_id', $employee->id)->where('status', 'active')->get()->sum(fn ($l) => (float) $l->amount - (float) $l->repaid);

        $leavePay = round($leaveDays * $daily, 2);
        $salaryDue = round((float) ($data['salary_days'] ?? 0) * $daily, 2);
        $otherDues = round((float) ($data['other_dues'] ?? 0), 2);
        $otherDeductions = round((float) ($data['other_deductions'] ?? 0), 2);

        return EndOfServiceSettlement::create([
            'employee_id' => $employee->id,
            'termination_date' => $date->toDateString(),
            'reason' => $data['reason'],
            'service_years' => $gratuity['years'],
            'last_wage' => $gratuity['wage'],
            'eos_full' => $gratuity['full'],
            'eos_amount' => $gratuity['amount'],
            'leave_days' => round($leaveDays, 2),
            'leave_encashment' => $leavePay,
            'salary_due' => $salaryDue,
            'other_dues' => $otherDues,
            'loan_deduction' => round($loans, 2),
            'other_deductions' => $otherDeductions,
            'net_amount' => round($gratuity['amount'] + $leavePay + $salaryDue + $otherDues - $loans - $otherDeductions, 2),
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function approveSettlement(EndOfServiceSettlement $settlement): void
    {
        DB::transaction(function () use ($settlement) {
            $settlement = EndOfServiceSettlement::with('employee')->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'draft') {
                throw ValidationException::withMessages(['settlement' => 'المخالصة معتمدة مسبقاً.']);
            }

            $eos = (float) $settlement->eos_amount;
            $provision = max(0, (float) Account::whereKey($this->accounts->id('end_of_service_provision'))->value('balance'));
            $fromProvision = round(min($eos, $provision), 2);
            // Leave pay comes out of the leave provision first.
            $leaveProvision = $this->accounts->find('leave_provision') ? max(0, (float) Account::whereKey($this->accounts->id('leave_provision'))->value('balance')) : 0;
            $fromLeaveProvision = round(min((float) $settlement->leave_encashment, $leaveProvision), 2);
            $dues = round((float) $settlement->leave_encashment - $fromLeaveProvision + (float) $settlement->salary_due + (float) $settlement->other_dues - (float) $settlement->other_deductions, 2);
            $cc = $settlement->employee->cost_center_id;
            $party = ['party_type' => 'employee', 'party_id' => $settlement->employee_id];

            $this->posting->post([
                'date' => $settlement->termination_date,
                'reference' => 'EOS-'.$settlement->id,
                'description' => 'مخالصة نهائية - '.$settlement->employee->name,
                'source' => $settlement,
                'lines' => array_values(array_filter([
                    $fromProvision > 0 ? ['role' => 'end_of_service_provision', 'debit' => $fromProvision] : null,
                    $eos - $fromProvision > 0 ? ['role' => 'end_of_service_expense', 'debit' => round($eos - $fromProvision, 2), 'cost_center_id' => $cc] : null,
                    $fromLeaveProvision > 0 ? ['role' => 'leave_provision', 'debit' => $fromLeaveProvision] : null,
                    $dues > 0 ? ['role' => 'salaries_expense', 'debit' => $dues, 'cost_center_id' => $cc] : null,
                    $dues < 0 ? ['role' => 'salaries_expense', 'credit' => -$dues, 'cost_center_id' => $cc] : null,
                    (float) $settlement->loan_deduction > 0 ? ['role' => 'employee_advances', 'credit' => (float) $settlement->loan_deduction] + $party : null,
                    (float) $settlement->net_amount > 0 ? ['role' => 'salaries_payable', 'credit' => (float) $settlement->net_amount] + $party : null,
                    (float) $settlement->net_amount < 0 ? ['role' => 'employee_advances', 'debit' => -(float) $settlement->net_amount] + $party : null,
                ])),
            ]);

            EmployeeLoan::where('employee_id', $settlement->employee_id)->where('status', 'active')->update(['repaid' => DB::raw('amount'), 'status' => 'settled']);
            $settlement->employee->update(['status' => 'terminated', 'termination_date' => $settlement->termination_date, 'termination_reason' => $settlement->reason]);
            // A former employee keeps no access to the company's system.
            if ($user = $settlement->employee->user) {
                $user->forceFill(['status' => 'inactive'])->save();
                $user->revokeAccess();
            }
            $settlement->update(['status' => 'approved', 'approved_by' => auth()->id()]);
        });
    }

    public function paySettlement(EndOfServiceSettlement $settlement, ?int $bankAccountId, string $date): void
    {
        DB::transaction(function () use ($settlement, $bankAccountId, $date) {
            $settlement = EndOfServiceSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'approved' || (float) $settlement->net_amount <= 0) {
                throw ValidationException::withMessages(['settlement' => 'المخالصة غير معتمدة أو لا يوجد مبلغ مستحق.']);
            }

            $this->posting->post([
                'date' => $date,
                'reference' => 'EOS-'.$settlement->id,
                'description' => 'صرف مخالصة نهائية',
                'source' => $settlement,
                'lines' => [
                    ['role' => 'salaries_payable', 'debit' => (float) $settlement->net_amount, 'party_type' => 'employee', 'party_id' => $settlement->employee_id],
                    ['account_id' => $this->poster->cashAccount($bankAccountId, 'bank_transfer'), 'credit' => (float) $settlement->net_amount],
                ],
            ]);

            $settlement->update(['status' => 'paid', 'bank_account_id' => $bankAccountId]);
        });
    }
}

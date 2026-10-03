<?php

namespace App\Services\HR;

use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EndOfServiceSettlement;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
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

    // ----- Loans -----

    public function approveLoan(EmployeeLoan $loan, ?int $bankAccountId): void
    {
        DB::transaction(function () use ($loan, $bankAccountId) {
            $loan = EmployeeLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
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
        $rules = config('hr.end_of_service');
        // Calendar years of service (leap-year safe), fractions pro rata.
        $years = $employee->hire_date ? max(0, (float) $employee->hire_date->diffInYears($date)) : 0;
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
        foreach (config('hr.end_of_service.resignation') as [$below, $share]) {
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
     * Draft final settlement: gratuity, leave encashment, unpaid salary days,
     * less outstanding loans.
     */
    public function draftSettlement(Employee $employee, array $data): EndOfServiceSettlement
    {
        if (EndOfServiceSettlement::where('employee_id', $employee->id)->whereIn('status', ['draft', 'approved', 'paid'])->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'توجد مخالصة لهذا الموظف.']);
        }

        $date = Carbon::parse($data['termination_date']);
        $gratuity = $this->gratuity($employee, $date, $data['reason']);
        $daily = $gratuity['wage'] / config('hr.month_days', 30);
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
            $dues = round((float) $settlement->leave_encashment + (float) $settlement->salary_due + (float) $settlement->other_dues - (float) $settlement->other_deductions, 2);
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
                    $dues > 0 ? ['role' => 'salaries_expense', 'debit' => $dues, 'cost_center_id' => $cc] : null,
                    $dues < 0 ? ['role' => 'salaries_expense', 'credit' => -$dues, 'cost_center_id' => $cc] : null,
                    (float) $settlement->loan_deduction > 0 ? ['role' => 'employee_advances', 'credit' => (float) $settlement->loan_deduction] + $party : null,
                    (float) $settlement->net_amount > 0 ? ['role' => 'salaries_payable', 'credit' => (float) $settlement->net_amount] + $party : null,
                    (float) $settlement->net_amount < 0 ? ['role' => 'employee_advances', 'debit' => -(float) $settlement->net_amount] + $party : null,
                ])),
            ]);

            EmployeeLoan::where('employee_id', $settlement->employee_id)->where('status', 'active')->update(['repaid' => DB::raw('amount'), 'status' => 'settled']);
            $settlement->employee->update(['status' => 'terminated', 'termination_date' => $settlement->termination_date, 'termination_reason' => $settlement->reason]);
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

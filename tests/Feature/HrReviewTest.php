<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\HR\EmployeeFinanceService;
use App\Services\HR\LeaveService;
use App\Services\HR\PayrollCalculator;
use App\Services\HR\PayrollService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HR manager review: payroll, leave, settlement and approval controls.
 */
class HrReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected int $sequence = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        app(LeaveService::class)->ensureTypes($this->company);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function employee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'name' => 'أحمد', 'job_title' => 'محاسب', 'department' => 'المالية', 'hire_date' => '2020-01-01', 'nationality' => 'SA',
            'basic_salary' => 6000, 'housing_allowance' => 1500, 'transport_allowance' => 0, 'other_allowances' => 0, 'total_salary' => 7500,
            'status' => 'active', 'gosi_registered' => false, 'employee_number' => 'E'.(++$this->sequence),
        ], $attributes));
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find(app(AccountMap::class)->id($role))->balance, 2);
    }

    protected function leave(Employee $employee, string $code, string $from, string $to, string $status = 'approved'): LeaveRequest
    {
        return LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => LeaveType::where('code', $code)->value('id'),
            'start_date' => $from, 'end_date' => $to, 'days' => Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1, 'status' => $status]);
    }

    // ------------------------------------------------------------------ H1

    public function test_final_settlement_and_payroll_never_pay_the_last_days_twice(): void
    {
        $leaver = $this->employee();
        $this->employee(['name' => 'باقٍ']);
        $finance = app(EmployeeFinanceService::class);

        $settlement = $finance->draftSettlement($leaver, ['termination_date' => '2026-06-15', 'reason' => 'termination', 'salary_days' => 15]);
        $this->assertSame(3750.0, (float) $settlement->salary_due); // 15 x 7500 / 30
        $finance->approveSettlement($settlement);

        $payroll = app(PayrollService::class)->generate(2026, 6);
        $this->assertSame([Employee::where('name', 'باقٍ')->value('id')], $payroll->items()->pluck('employee_id')->all());
    }

    public function test_settlement_refuses_salary_days_already_in_an_approved_payroll(): void
    {
        $leaver = $this->employee();
        $service = app(PayrollService::class);
        $service->approve($service->generate(2026, 6));

        $this->expectException(ValidationException::class);
        app(EmployeeFinanceService::class)->draftSettlement($leaver, ['termination_date' => '2026-06-30', 'reason' => 'termination', 'salary_days' => 30]);
    }

    public function test_absence_inside_approved_leave_is_not_deducted_twice(): void
    {
        $e = $this->employee();
        $this->leave($e, 'unpaid', '2026-06-08', '2026-06-09');
        foreach (['2026-06-08', '2026-06-09', '2026-06-10'] as $date) {
            AttendanceRecord::create(['employee_id' => $e->id, 'date' => $date, 'status' => 'absent']);
        }

        $pay = app(PayrollCalculator::class)->calculate($e, 2026, 6);
        // Two unpaid leave days + one real absence = three days at 250.
        $this->assertSame(1, $pay['breakdown']['absent_days']);
        $this->assertSame(750.0, $pay['absence_deduction']);
    }

    public function test_absence_never_takes_the_wage_below_nil(): void
    {
        $e = $this->employee(['hire_date' => '2026-06-20']);
        foreach (range(1, 30) as $day) {
            AttendanceRecord::create(['employee_id' => $e->id, 'date' => sprintf('2026-06-%02d', $day), 'status' => 'absent']);
        }

        $pay = app(PayrollCalculator::class)->calculate($e, 2026, 6);
        $this->assertSame(0.0, $pay['gross_salary']);
        $this->assertGreaterThanOrEqual(0.0, $pay['net_salary']);
    }

    public function test_nobody_approves_their_own_leave_or_loan(): void
    {
        $me = $this->employee(['user_id' => auth()->id()]);
        $leave = $this->leave($me, 'annual', '2026-07-01', '2026-07-03', 'pending');
        $this->post(route('hr.leaves.decide', $leave), ['approve' => true])->assertSessionHasErrors('approval');
        $this->assertSame('pending', $leave->fresh()->status);

        $loan = EmployeeLoan::create(['employee_id' => $me->id, 'date' => '2026-06-15', 'amount' => 1000, 'monthly_installment' => 500, 'first_deduction_month' => '2026-07-01', 'status' => 'pending']);
        $this->post(route('hr.loans.decide', $loan), ['approve' => true])->assertSessionHasErrors('approval');
        $this->post(route('hr.loans.decide', $loan), ['approve' => false])->assertSessionHasErrors('approval');
        $this->assertSame('pending', $loan->fresh()->status);

        // Someone else's request is fine.
        $other = $this->leave($this->employee(['name' => 'سالم']), 'annual', '2026-07-01', '2026-07-03', 'pending');
        $this->post(route('hr.leaves.decide', $other), ['approve' => true])->assertSessionHasNoErrors();
    }

    public function test_leave_balance_is_checked_again_at_approval(): void
    {
        $e = $this->employee(['hire_date' => '2026-01-01']); // ~10 days accrued by mid-July
        $leaves = app(LeaveService::class);
        $annual = LeaveType::where('code', 'annual')->value('id');
        $first = $leaves->request($e, ['leave_type_id' => $annual, 'start_date' => '2026-07-10', 'end_date' => '2026-07-15']);
        $second = $leaves->request($e, ['leave_type_id' => $annual, 'start_date' => '2026-07-20', 'end_date' => '2026-07-25']);

        $leaves->decide($first, true);
        $this->expectException(ValidationException::class);
        $leaves->decide($second, true);
    }

    public function test_a_fine_is_capped_at_five_days_wage(): void
    {
        $e = $this->employee();
        $payroll = app(PayrollService::class)->generate(2026, 6);

        // 7500 / 30 = 250 a day -> at most 1250 for one violation.
        $this->post(route('hr.payroll.adjustments.store', $payroll), ['employee_id' => $e->id, 'type' => 'penalty', 'amount' => 1300])->assertSessionHasErrors('amount');
        $this->post(route('hr.payroll.adjustments.store', $payroll), ['employee_id' => $e->id, 'type' => 'penalty', 'amount' => 1250])->assertSessionHasNoErrors();
        $this->post(route('hr.payroll.adjustments.store', $payroll), ['employee_id' => $e->id, 'type' => 'deduction', 'amount' => 1300])->assertSessionHasNoErrors();
    }

    public function test_final_settlement_closes_the_leavers_system_access(): void
    {
        $user = $this->userWithRole($this->company, 'employee');
        $e = $this->employee(['user_id' => $user->id]);
        $finance = app(EmployeeFinanceService::class);
        $finance->approveSettlement($finance->draftSettlement($e, ['termination_date' => '2026-06-15', 'reason' => 'resignation']));

        $this->assertSame('inactive', $user->fresh()->status);
    }

    public function test_long_unpaid_leave_is_not_service_for_the_gratuity(): void
    {
        $e = $this->employee(['hire_date' => '2021-06-15']);
        $finance = app(EmployeeFinanceService::class);
        $date = Carbon::parse('2026-06-15');
        $this->assertEqualsWithDelta(5.0, $finance->gratuity($e, $date, 'termination')['years'], 0.001);

        $this->leave($e, 'unpaid', '2024-01-01', '2024-01-10'); // 10 days: still service
        $this->assertEqualsWithDelta(5.0, $finance->gratuity($e, $date, 'termination')['years'], 0.001);

        $this->leave($e, 'unpaid', '2025-03-01', '2025-03-26'); // total 36 days: excluded
        $this->assertEqualsWithDelta(5 - 36 / 365, $finance->gratuity($e, $date, 'termination')['years'], 0.001);
    }
}

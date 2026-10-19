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
use App\Services\HR\AttendanceService;
use App\Services\HR\EmployeeFinanceService;
use App\Services\HR\LeaveService;
use App\Services\HR\PayrollCalculator;
use App\Services\HR\PayrollService;
use App\Services\Saas\AlertService;
use App\Services\Settings\CompanyPolicy;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
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

    // ------------------------------------------------------------------ H2

    public function test_unapproving_a_payroll_reverses_it_and_restores_loans(): void
    {
        $e = $this->employee();
        $loan = EmployeeLoan::create(['employee_id' => $e->id, 'date' => '2026-05-01', 'amount' => 1000, 'monthly_installment' => 400, 'first_deduction_month' => '2026-06-01', 'status' => 'active', 'repaid' => 0]);
        $service = app(PayrollService::class);
        $payroll = $service->generate(2026, 6);
        $service->approve($payroll);
        $this->assertSame(400.0, (float) $loan->fresh()->repaid);
        $this->assertGreaterThan(0, $this->balance('salaries_payable'));

        $this->post(route('hr.payroll.unapprove', $payroll))->assertSessionHasNoErrors();
        $this->assertSame('draft', $payroll->fresh()->status);
        $this->assertSame(0.0, (float) $loan->fresh()->repaid);
        $this->assertSame(0.0, $this->balance('salaries_payable'));

        // Approve again: the same instalment is recovered once.
        $service->approve($payroll->fresh());
        $this->assertSame(400.0, (float) $loan->fresh()->repaid);
    }

    public function test_a_paid_or_superseded_payroll_cannot_be_unapproved(): void
    {
        $this->employee();
        $service = app(PayrollService::class);
        $june = $service->generate(2026, 6);
        $service->approve($june);
        $july = $service->generate(2026, 7);
        $service->approve($july);

        $this->post(route('hr.payroll.unapprove', $june))->assertSessionHasErrors('payroll');
        $this->post(route('hr.payroll.unapprove', $july))->assertSessionHasNoErrors();
    }

    protected function employeeForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'خالد', 'nationality' => 'SA', 'id_type' => 'national_id', 'national_id' => '1098765432', 'job_title' => 'فني',
            'hire_date' => '2025-01-01', 'contract_type' => 'unlimited', 'basic_salary' => 5000, 'housing_allowance' => 1250, 'status' => 'active',
        ], $overrides);
    }

    public function test_employee_identity_and_iban_are_validated(): void
    {
        $this->post(route('hr.employees.store'), $this->employeeForm(['national_id' => '2098765432']))->assertSessionHasErrors('national_id');
        $this->post(route('hr.employees.store'), $this->employeeForm(['iban' => 'SA0480000000608010167519']))->assertSessionHasErrors('iban');
        $this->post(route('hr.employees.store'), $this->employeeForm(['iban' => 'SA03 8000 0000 6080 1016 7519']))->assertSessionHasNoErrors();
        $this->assertSame('SA0380000000608010167519', Employee::where('national_id', '1098765432')->value('iban'));
        // The same ID cannot be registered twice.
        $this->post(route('hr.employees.store'), $this->employeeForm(['name' => 'مكرر']))->assertSessionHasErrors('national_id');
        $this->post(route('hr.employees.store'), $this->employeeForm(['name' => 'مقيم', 'nationality' => 'IN', 'id_type' => 'iqama', 'national_id' => '2098765432']))->assertSessionHasNoErrors();
    }

    public function test_users_without_salary_access_neither_see_nor_wipe_salaries(): void
    {
        $e = $this->employee(['national_id' => '1011111111', 'id_type' => 'national_id', 'contract_type' => 'unlimited', 'iban' => 'SA0380000000608010167519']);
        $clerk = $this->userWithRole($this->company, 'employee');
        $this->givePermissions($clerk, ['hr.employees.view', 'hr.employees.edit']);
        $this->actingAs($clerk->fresh());

        $props = $this->get(route('hr.employees.edit', $e))->assertOk()->viewData('page')['props']['employee'];
        $this->assertArrayNotHasKey('basic_salary', $props);
        $this->assertArrayNotHasKey('iban', $props);

        $this->put(route('hr.employees.update', $e), $this->employeeForm(['name' => 'أحمد المحدث', 'national_id' => '1011111111', 'basic_salary' => 0, 'housing_allowance' => 0, 'iban' => '']))->assertSessionHasNoErrors();
        $e->refresh();
        $this->assertSame('أحمد المحدث', $e->name);
        $this->assertSame(6000.0, (float) $e->basic_salary);
        $this->assertSame('SA0380000000608010167519', $e->iban);

        $this->post(route('hr.employees.pay-items.store', $e), ['kind' => 'allowance', 'name' => 'بدل', 'amount' => 500])->assertForbidden();
    }

    public function test_status_cannot_jump_to_terminated_with_open_loans(): void
    {
        $e = $this->employee(['national_id' => '1011111112', 'id_type' => 'national_id', 'contract_type' => 'unlimited']);
        EmployeeLoan::create(['employee_id' => $e->id, 'date' => '2026-05-01', 'amount' => 1000, 'monthly_installment' => 400, 'first_deduction_month' => '2026-06-01', 'status' => 'active', 'repaid' => 0]);

        $this->put(route('hr.employees.update', $e), $this->employeeForm(['national_id' => '1011111112', 'status' => 'terminated']))->assertSessionHasErrors('status');
    }

    public function test_hr_alerts_for_contracts_probation_and_gosi_numbers(): void
    {
        $this->employee(['contract_type' => 'limited', 'contract_end' => '2026-07-20']);
        $this->employee(['probation_end' => '2026-06-20']);
        $this->employee(['gosi_registered' => true, 'gosi_number' => null]);

        $kinds = collect(app(AlertService::class)->collect())->pluck('kind')->all();
        $this->assertContains('contracts_ending', $kinds);
        $this->assertContains('probation_ending', $kinds);
        $this->assertContains('gosi_numbers', $kinds);
    }

    public function test_employee_withdraws_a_pending_request_and_sees_the_rejection_note(): void
    {
        $user = $this->userWithRole($this->company, 'employee');
        $me = $this->employee(['user_id' => $user->id]);
        $pending = $this->leave($me, 'annual', '2026-07-01', '2026-07-02', 'pending');
        $rejected = $this->leave($me, 'annual', '2026-08-01', '2026-08-02', 'pending');

        $this->post(route('hr.leaves.decide', $rejected), ['approve' => false, 'note' => 'ضغط العمل في أغسطس'])->assertSessionHasNoErrors();

        $this->actingAs($user);
        $this->post(route('hr.me.leaves.cancel', $pending))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->post(route('hr.me.leaves.cancel', $rejected))->assertStatus(422);

        $requests = collect($this->get(route('hr.me'))->assertOk()->viewData('page')['props']['leaveRequests']);
        $this->assertSame('ضغط العمل في أغسطس', $requests->firstWhere('id', $rejected->id)['decision_note']);

        $other = $this->leave($this->employee(['name' => 'غيري']), 'annual', '2026-07-05', '2026-07-06', 'pending');
        $this->post(route('hr.me.leaves.cancel', $other))->assertNotFound();
    }

    protected function givePermissions($user, array $permissions): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $user->givePermissionTo($permissions);
    }

    // ------------------------------------------------------------------ H3

    public function test_a_mid_month_raise_is_prorated_and_logged(): void
    {
        $e = $this->employee(['national_id' => '1022222222', 'id_type' => 'national_id', 'contract_type' => 'unlimited']);

        // From 16 June (30-day month): 15 days at 6000/1500, 15 days at 9000/1500.
        $this->put(route('hr.employees.update', $e), $this->employeeForm(['name' => 'أحمد', 'national_id' => '1022222222', 'basic_salary' => 9000, 'housing_allowance' => 1500,
            'salary_effective_date' => '2026-06-16', 'salary_change_reason' => 'ترقية']))->assertSessionHasNoErrors();
        $change = $e->salaryChanges()->first();
        $this->assertSame(6000.0, (float) $change->previous['basic_salary']);
        $this->assertSame('ترقية', $change->reason);

        $pay = app(PayrollCalculator::class)->calculate($e->fresh(), 2026, 6);
        $this->assertSame(7500.0, $pay['basic_salary']);
        $this->assertSame(1500.0, $pay['housing_allowance']);
        // May was entirely at the old pay; July entirely at the new one.
        $this->assertSame(6000.0, app(PayrollCalculator::class)->calculate($e->fresh(), 2026, 5)['basic_salary']);
        $this->assertSame(9000.0, app(PayrollCalculator::class)->calculate($e->fresh(), 2026, 7)['basic_salary']);
    }

    public function test_a_raise_cannot_take_effect_inside_an_approved_payroll(): void
    {
        $e = $this->employee(['national_id' => '1022222223', 'id_type' => 'national_id', 'contract_type' => 'unlimited']);
        $service = app(PayrollService::class);
        $service->approve($service->generate(2026, 6));

        $this->put(route('hr.employees.update', $e), $this->employeeForm(['national_id' => '1022222223', 'basic_salary' => 9000, 'housing_allowance' => 1500, 'salary_effective_date' => '2026-06-01']))
            ->assertSessionHasErrors('salary_effective_date');
        $this->put(route('hr.employees.update', $e), $this->employeeForm(['national_id' => '1022222223', 'basic_salary' => 9000, 'housing_allowance' => 1500, 'salary_effective_date' => '2026-07-01']))
            ->assertSessionHasNoErrors();
        $this->assertSame(9000.0, (float) $e->fresh()->basic_salary);
    }

    public function test_public_holidays_are_not_taken_from_annual_leave_nor_marked_absent(): void
    {
        $this->post(route('hr.holidays.store'), ['name' => 'عطلة عيد الأضحى', 'start_date' => '2026-05-26', 'end_date' => '2026-05-29'])->assertSessionHasNoErrors();
        $e = $this->employee();

        $leave = app(LeaveService::class)->request($e, ['leave_type_id' => LeaveType::where('code', 'annual')->value('id'), 'start_date' => '2026-05-24', 'end_date' => '2026-06-02']);
        $this->assertSame(6.0, (float) $leave->days); // 10 calendar days less 4 holiday days

        $record = app(AttendanceService::class)->record($e, '2026-05-27', null, null);
        $this->assertSame('holiday', $record->status);
    }

    public function test_lateness_is_deducted_only_when_the_policy_is_on(): void
    {
        $e = $this->employee();
        foreach (['2026-06-02', '2026-06-03'] as $day) {
            AttendanceRecord::create(['employee_id' => $e->id, 'date' => $day, 'status' => 'present', 'late_minutes' => 75]);
        }
        $calc = app(PayrollCalculator::class);
        $this->assertSame(0.0, $calc->calculate($e, 2026, 6)['absence_deduction']);

        app(CompanyPolicy::class)->set($this->company, 'hr.late_deduction', ['enabled' => true, 'monthly_grace_minutes' => 30]);
        $pay = $calc->calculate($e, 2026, 6);
        // 150 - 30 = 120 minutes at 7500 / (30 x 8 x 60) = 0.5208 a minute.
        $this->assertSame(120, $pay['breakdown']['late_deducted_minutes']);
        $this->assertSame(62.5, $pay['absence_deduction']);
    }

    public function test_date_columns_reach_the_browser_as_the_same_day(): void
    {
        config(['app.timezone' => 'Asia/Riyadh']);
        date_default_timezone_set('Asia/Riyadh');
        $leave = $this->leave($this->employee(), 'annual', '2026-07-01', '2026-07-03', 'pending');

        $array = LeaveRequest::find($leave->id)->toArray();
        $this->assertStringStartsWith('2026-07-01', $array['start_date']);
        $this->assertStringStartsWith('2026-07-03', $array['end_date']);
        $this->assertStringEndsWith('+03:00', $array['start_date']);
        $this->assertStringStartsWith('2026-07-01', auth()->user()->forceFill(['email_verified_at' => '2026-07-01 00:30:00'])->toArray()['email_verified_at']);
    }
}

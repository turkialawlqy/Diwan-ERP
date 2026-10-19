<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\EmployeeLoan;
use App\Models\JournalEntryLine;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\WorkShift;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\HR\AttendanceService;
use App\Services\HR\EmployeeFinanceService;
use App\Services\HR\LeaveService;
use App\Services\HR\PayrollCalculator;
use App\Services\HR\PayrollService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HrPayrollTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

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

    protected int $sequence = 100;

    protected function employee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'name' => 'أحمد', 'job_title' => 'محاسب', 'department' => 'المالية', 'hire_date' => '2020-01-01', 'nationality' => 'SA',
            'basic_salary' => 10000, 'housing_allowance' => 2500, 'transport_allowance' => 1000, 'other_allowances' => 0, 'total_salary' => 13500,
            'status' => 'active', 'gosi_registered' => true, 'employee_number' => 'E'.(++$this->sequence),
        ], $attributes));
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find(app(AccountMap::class)->id($role))->balance, 2);
    }

    public function test_gosi_rates_for_saudi_non_saudi_and_new_entrant(): void
    {
        $calc = app(PayrollCalculator::class);
        $june = Carbon::parse('2026-06-30');

        [$ee, $er] = $calc->gosi($this->employee(), 12500, 1, $june);
        $this->assertSame(1218.75, $ee);  // 9.75%
        $this->assertSame(1468.75, $er);  // 11.75%

        [$ee, $er] = $calc->gosi($this->employee(['nationality' => 'EG']), 12500, 1, $june);
        $this->assertSame(0.0, $ee);
        $this->assertSame(250.0, $er);    // 2% hazards

        // Joined under the 2024 law: annuity 9.5% from July 2025 (10% from July 2026).
        [$ee, $er] = $calc->gosi($this->employee(['gosi_join_date' => '2025-01-01']), 12500, 1, $june);
        $this->assertSame(1281.25, $ee);  // 10.25%
        $this->assertSame(1531.25, $er);  // 12.25%

        // Capped at 45,000.
        [$ee] = $calc->gosi($this->employee(), 60000, 1, $june);
        $this->assertSame(4387.5, $ee);
    }

    public function test_payroll_with_proration_overtime_absence_loans_and_posting(): void
    {
        $full = $this->employee();
        $joiner = $this->employee(['name' => 'سارة', 'hire_date' => '2026-06-16', 'nationality' => 'IN']);   // half month

        EmployeeAdjustment::create(['employee_id' => $full->id, 'year' => 2026, 'month' => 6, 'type' => 'overtime_hours', 'amount' => 10]);
        app(AttendanceService::class)->record($full, '2026-06-10', null, null, 'manual', 'absent');
        $loan = EmployeeLoan::create(['employee_id' => $full->id, 'date' => '2026-05-01', 'amount' => 3000, 'monthly_installment' => 1000, 'first_deduction_month' => '2026-06-01', 'status' => 'pending']);
        app(EmployeeFinanceService::class)->approveLoan($loan, null);

        $payroll = app(PayrollService::class)->generate(2026, 6);
        $a = $payroll->items()->where('employee_id', $full->id)->first();
        $b = $payroll->items()->where('employee_id', $joiner->id)->first();

        // Overtime: 10h x (13500/240 + 0.5 x 10000/240) = 10 x (56.25 + 20.8333)
        $this->assertSame(770.83, (float) $a->overtime);
        $this->assertSame(450.0, (float) $a->absence_deduction);      // 1 day x 13500/30
        $this->assertSame(1000.0, (float) $a->loan_deduction);
        $this->assertSame(1218.75, (float) $a->gosi_employee);
        $this->assertSame(13820.83, (float) $a->gross_salary);        // 13500 + 770.83 - 450
        $this->assertSame(11602.08, (float) $a->net_salary);          // - 1218.75 - 1000

        $this->assertSame(15, (int) $b->days_worked);
        $this->assertSame(5000.0, (float) $b->basic_salary);
        $this->assertSame(0.0, (float) $b->gosi_employee);
        $this->assertSame(125.0, (float) $b->gosi_employer);          // 2% of 6250

        app(PayrollService::class)->approve($payroll);
        $this->assertSame('approved', $payroll->fresh()->status);
        $this->assertSame(1000.0, (float) $loan->fresh()->repaid);
        $this->assertSame((float) $payroll->fresh()->net_total, $this->balance('salaries_payable'));
        $this->assertSame(2000.0, $this->balance('employee_advances'));

        app(PayrollService::class)->pay($payroll->fresh(), null, '2026-06-30');
        $this->assertSame(0.0, $this->balance('salaries_payable'));
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);

        $this->expectException(ValidationException::class);
        app(PayrollService::class)->generate(2026, 6);
    }

    public function test_sick_leave_tiers_and_unpaid_leave(): void
    {
        $e = $this->employee(['basic_salary' => 9000, 'housing_allowance' => 0, 'transport_allowance' => 0, 'total_salary' => 9000]);
        $sick = LeaveType::where('code', 'sick')->first();
        $unpaid = LeaveType::where('code', 'unpaid')->first();
        $leaves = app(LeaveService::class);

        // 35 sick days already this year → 5 June days fall in the 75% tier... plus 5 more.
        foreach ([['2026-01-01', '2026-02-04'], ['2026-06-01', '2026-06-05']] as [$s, $t]) {
            $leaves->decide($leaves->request($e, ['leave_type_id' => $sick->id, 'start_date' => $s, 'end_date' => $t]), true);
        }
        $leaves->decide($leaves->request($e, ['leave_type_id' => $unpaid->id, 'start_date' => '2026-06-20', 'end_date' => '2026-06-21']), true);

        $result = app(PayrollCalculator::class)->calculate($e, 2026, 6);
        // 5 sick days at 75% → 1.25 days deducted; 2 unpaid days.
        $this->assertSame(3.25 * 300, $result['absence_deduction']);
    }

    public function test_annual_leave_balance_and_overlap(): void
    {
        $e = $this->employee(['hire_date' => '2025-06-15']);
        $leaves = app(LeaveService::class);
        $annual = LeaveType::where('code', 'annual')->first();

        $this->assertEqualsWithDelta(21.0, $leaves->balance($e)['balance'], 0.1);
        $leaves->decide($leaves->request($e, ['leave_type_id' => $annual->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-10']), true);
        $this->assertEqualsWithDelta(12.5, $leaves->balance($e, Carbon::parse('2026-07-11'))['balance'], 0.1); // 391 days accrued - 10 taken

        $this->expectException(ValidationException::class);
        $leaves->request($e, ['leave_type_id' => $annual->id, 'start_date' => '2026-07-05', 'end_date' => '2026-07-06']);
    }

    public function test_end_of_service_rules_and_settlement(): void
    {
        $finance = app(EmployeeFinanceService::class);
        $date = Carbon::parse('2026-06-15');

        // 8 years, wage 13,500: 5 x 6,750 + 3 x 13,500 = 74,250.
        $veteran = $this->employee(['hire_date' => '2018-06-15']);
        $g = $finance->gratuity($veteran, $date, 'termination');
        $this->assertSame(74250.0, $g['amount']);
        $this->assertSame(49500.0, $finance->gratuity($veteran, $date, 'resignation')['amount']);
        $this->assertSame(0.0, $finance->gratuity($veteran, $date, 'article_80')['amount']);
        $this->assertSame(0.0, $finance->gratuity($this->employee(['hire_date' => '2025-01-01']), $date, 'resignation')['amount']);

        $finance->accrueProvision($date);
        $provision = $this->balance('end_of_service_provision');
        $this->assertGreaterThan(74000, $provision);

        $settlement = $finance->draftSettlement($veteran, ['termination_date' => '2026-06-15', 'reason' => 'termination', 'salary_days' => 15]);
        $this->assertSame(6750.0, (float) $settlement->salary_due);
        $finance->approveSettlement($settlement);

        $this->assertSame('terminated', $veteran->fresh()->status);
        $this->assertSame((float) $settlement->net_amount, $this->balance('salaries_payable'));
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);
    }

    public function test_attendance_late_and_overtime_against_shift(): void
    {
        $shift = WorkShift::create(['name' => 'صباحي', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'grace_minutes' => 10, 'work_days' => [7, 1, 2, 3, 4], 'is_default' => true]);
        $e = $this->employee(['shift_id' => $shift->id]);

        $record = app(AttendanceService::class)->record($e, '2026-06-14', '08:25', '19:00'); // Sunday
        $this->assertSame(25, $record->late_minutes);
        $this->assertSame(575, $record->worked_minutes);   // 10h35 - 60
        $this->assertSame(95, $record->overtime_minutes);  // beyond 8h

        $weekend = app(AttendanceService::class)->record($e, '2026-06-12', null, null); // Friday
        $this->assertSame('weekend', $weekend->status);
    }

    public function test_hr_pages_and_self_service(): void
    {
        $e = $this->employee(['employee_number' => 'E100']);
        $this->post(route('hr.employees.store'), ['name' => 'خالد', 'nationality' => 'SA', 'id_type' => 'national_id', 'job_title' => 'مهندس', 'hire_date' => '2026-01-01',
            'contract_type' => 'unlimited', 'basic_salary' => 8000, 'housing_allowance' => 2000, 'status' => 'active', 'iban' => 'SA0380000000608010167519'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(10000.0, (float) Employee::where('name', 'خالد')->value('total_salary'));
        $this->assertNotNull(Employee::where('name', 'خالد')->value('employee_number'));

        $this->post(route('hr.payroll.store'), ['month' => 6, 'year' => 2026])->assertSessionHasNoErrors();
        $payroll = Payroll::firstOrFail();

        foreach ([route('hr.employees.index'), route('hr.employees.create'), route('hr.employees.show', $e), route('hr.employees.edit', $e), route('hr.organization'),
            route('hr.attendance.index'), route('hr.leaves.index'), route('hr.loans.index'), route('hr.settlements.index'), route('hr.payroll.index'),
            route('hr.payroll.show', $payroll), route('hr.payroll.payslip', [$payroll, $payroll->items()->first()])] as $url) {
            $this->get($url)->assertOk();
        }
        // Employees without a valid ID / IBAN: the salary file is refused with what to fix.
        $this->get(route('hr.payroll.wps', $payroll))->assertRedirect()->assertSessionHasErrors('wps');

        // Self service: a user sees only their own payslips.
        $user = $this->userWithRole($this->company, 'employee');
        $e->update(['user_id' => $user->id]);
        app(PayrollService::class)->approve($payroll);
        $this->actingAs($user);
        $this->get(route('hr.me'))->assertOk();
        $mine = $payroll->items()->where('employee_id', $e->id)->first();
        $other = $payroll->items()->where('employee_id', '!=', $e->id)->first();
        $this->get(route('hr.me.payslip', $mine))->assertOk();
        $this->get(route('hr.me.payslip', $other))->assertNotFound();
        $this->get(route('hr.payroll.index'))->assertForbidden();
    }
}

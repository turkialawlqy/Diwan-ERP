<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\PayrollItem;
use App\Services\Accounting\AccountingSetupService;
use App\Services\HR\LeaveService;
use App\Services\HR\PayrollService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The month's deduction reduces the loans it was calculated from, and undoing the payroll gives each loan back what it took. */
class PayrollLoanInstalmentsTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected PayrollService $payrolls;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        app(LeaveService::class)->ensureTypes($company);
        $this->employee = Employee::create(['name' => 'موظف', 'job_title' => 'محاسب', 'hire_date' => '2020-01-01', 'nationality' => 'SA', 'basic_salary' => 10000, 'housing_allowance' => 0, 'transport_allowance' => 0, 'other_allowances' => 0, 'total_salary' => 10000, 'status' => 'active', 'gosi_registered' => false, 'employee_number' => 'E1']);
        $this->payrolls = app(PayrollService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function loan(float $amount, float $instalment, string $date, string $first, float $repaid = 0): EmployeeLoan
    {
        return EmployeeLoan::create(['employee_id' => $this->employee->id, 'date' => $date, 'amount' => $amount, 'monthly_installment' => $instalment, 'first_deduction_month' => $first, 'status' => 'active', 'repaid' => $repaid]);
    }

    protected function repaid(EmployeeLoan ...$loans): array
    {
        return array_map(fn ($loan) => (float) $loan->fresh()->repaid, $loans);
    }

    public function test_an_older_loan_not_yet_due_is_left_alone(): void
    {
        $notDue = $this->loan(5000, 1000, '2026-01-01', '2026-09-01');
        $due = $this->loan(3000, 500, '2026-04-01', '2026-06-01');

        $payroll = $this->payrolls->generate(2026, 6);
        $this->payrolls->approve($payroll->fresh());

        $this->assertSame([0.0, 500.0], $this->repaid($notDue, $due));
    }

    public function test_each_due_loan_pays_its_own_instalment(): void
    {
        $big = $this->loan(5000, 1000, '2026-01-01', '2026-02-01');
        $small = $this->loan(5000, 300, '2026-03-01', '2026-04-01');

        $payroll = $this->payrolls->generate(2026, 6);
        $this->payrolls->approve($payroll->fresh());

        $this->assertSame([1000.0, 300.0], $this->repaid($big, $small));
    }

    public function test_undoing_the_payroll_gives_each_loan_back_what_it_paid(): void
    {
        $older = $this->loan(5000, 1000, '2026-01-01', '2026-02-01');
        $newer = $this->loan(5000, 1000, '2026-03-01', '2026-04-01', 300);

        $payroll = $this->payrolls->generate(2026, 6);
        $this->payrolls->approve($payroll->fresh());
        $this->assertSame([1000.0, 1300.0], $this->repaid($older, $newer));

        $this->payrolls->unapprove($payroll->fresh());

        $this->assertSame([0.0, 300.0], $this->repaid($older, $newer));
    }

    public function test_a_settled_loan_is_active_again_when_the_payroll_that_settled_it_is_undone(): void
    {
        $loan = $this->loan(1000, 1000, '2026-01-01', '2026-02-01');
        $payroll = $this->payrolls->generate(2026, 6);
        $this->payrolls->approve($payroll->fresh());
        $this->assertSame('settled', $loan->fresh()->status);

        $this->payrolls->unapprove($payroll->fresh());

        $this->assertSame('active', $loan->fresh()->status);
        $this->assertSame(0.0, (float) $loan->fresh()->repaid);
    }

    public function test_a_deduction_the_legal_cap_reduced_goes_to_the_oldest_due_loan_first(): void
    {
        $first = $this->loan(20000, 4000, '2026-01-01', '2026-02-01');
        $second = $this->loan(20000, 4000, '2026-03-01', '2026-04-01');

        $payroll = $this->payrolls->generate(2026, 6);
        $deducted = (float) PayrollItem::where('payroll_id', $payroll->id)->value('loan_deduction');
        $this->assertLessThan(8000, $deducted, 'The cap should have reduced the instalments.');
        $this->payrolls->approve($payroll->fresh());

        $this->assertSame([4000.0, $deducted - 4000.0], $this->repaid($first, $second));
    }

    public function test_a_deduction_larger_than_the_instalments_still_comes_off_the_loans(): void
    {
        $loan = $this->loan(5000, 1000, '2026-01-01', '2026-02-01');
        $payroll = $this->payrolls->generate(2026, 6);
        $loan->update(['monthly_installment' => 400]); // changed after the payroll was calculated

        $this->payrolls->approve($payroll->fresh());

        $this->assertSame([1000.0], $this->repaid($loan), 'The ledger credited the whole deduction to the loan.');
    }

    public function test_a_payroll_approved_before_repayments_were_recorded_still_undoes(): void
    {
        $loan = $this->loan(5000, 1000, '2026-01-01', '2026-02-01');
        $payroll = $this->payrolls->generate(2026, 6);
        $this->payrolls->approve($payroll->fresh());
        $item = PayrollItem::where('payroll_id', $payroll->id)->firstOrFail();
        $breakdown = $item->breakdown;
        unset($breakdown['loan_repayments']);
        $item->update(['breakdown' => $breakdown]);

        $this->payrolls->unapprove($payroll->fresh());

        $this->assertSame([0.0], $this->repaid($loan));
    }
}

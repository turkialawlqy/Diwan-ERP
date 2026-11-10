<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Accounting\AccountingSetupService;
use App\Services\HR\LeaveService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\ObservesRowLocks;
use Tests\TestCase;

/** Approving leave never takes an employee's balance below zero, in whatever order the requests are approved. */
class LeaveApprovalBalanceTest extends TestCase
{
    use ObservesRowLocks, RefreshDatabase;

    protected Employee $employee;

    protected LeaveService $leaves;

    protected LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-01-01 09:00:00');
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $this->leaves = app(LeaveService::class);
        $this->leaves->ensureTypes($company);
        $this->annual = LeaveType::where('code', 'annual')->firstOrFail();
        // Ten months of service before the first leave: enough for one 15-day leave, not for two.
        $this->employee = Employee::create(['name' => 'موظف', 'employee_number' => 'E1', 'job_title' => 'موظف', 'hire_date' => '2029-03-01', 'nationality' => 'SA', 'basic_salary' => 5000, 'status' => 'active', 'leave_opening_balance' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function request(string $start, string $end, int $days = 15): LeaveRequest
    {
        return LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'start_date' => $start, 'end_date' => $end, 'days' => $days, 'status' => 'pending']);
    }

    public function test_the_second_of_two_leaves_the_balance_cannot_cover_is_refused(): void
    {
        $first = $this->request('2030-01-10', '2030-01-24');
        $second = $this->request('2030-02-19', '2030-03-05');

        $this->leaves->decide($first, true);
        $this->expectException(ValidationException::class);
        $this->leaves->decide($second, true);
    }

    public function test_approving_the_earlier_leave_after_the_later_one_cannot_overdraw_the_later_one(): void
    {
        $earlier = $this->request('2030-01-10', '2030-01-24');
        $later = $this->request('2030-02-19', '2030-03-05');

        $this->leaves->decide($later, true); // covered on its own first day

        try {
            $this->leaves->decide($earlier, true); // covered on its own day, but it would leave the later one without days
            $this->fail('The balance was overdrawn by approving the earlier leave second.');
        } catch (ValidationException) {
            $this->assertSame('pending', $earlier->fresh()->status);
        }
        $this->assertGreaterThanOrEqual(0, $this->leaves->balance($this->employee, Carbon::parse('2030-03-01'))['balance']);
    }

    public function test_leaves_the_balance_covers_are_all_approved(): void
    {
        $first = $this->request('2030-01-10', '2030-01-14', 5);
        $second = $this->request('2030-02-19', '2030-02-23', 5);

        $this->leaves->decide($second, true);
        $this->leaves->decide($first, true);

        $this->assertSame(['approved', 'approved'], [$first->fresh()->status, $second->fresh()->status]);
    }

    public function test_two_approvals_of_one_employee_wait_for_each_other_before_reading_the_balance(): void
    {
        $request = $this->request('2030-01-10', '2030-01-14', 5);

        $locked = $this->tablesLockedBy(fn () => $this->leaves->decide($request, true));

        $this->assertSame(['leave_requests', 'employees'], $locked);
    }
}

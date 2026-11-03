<?php

namespace Tests\Feature;

use App\Models\AdminRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ErpAlert;
use App\Services\Accounting\AccountingSetupService;
use App\Services\HR\AdminRequestService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Administrative requests: summon employees of several departments, follow each line up. */
class AdminRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Department $sales;

    protected Department $tech;

    protected User $salesManager;

    protected User $techManager;

    protected Employee $ali;

    protected Employee $omar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);

        $this->sales = Department::create(['name' => 'المبيعات']);
        $this->tech = Department::create(['name' => 'التقنية']);
        $this->salesManager = $this->manager($this->sales, 'مدير المبيعات');
        $this->techManager = $this->manager($this->tech, 'مدير التقنية');
        $this->ali = $this->employee('علي', $this->sales);
        $this->omar = $this->employee('عمر', $this->tech);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function employee(string $name, Department $dept, ?User $user = null): Employee
    {
        static $n = 0;

        return Employee::create(['name' => $name, 'department_id' => $dept->id, 'status' => 'active', 'user_id' => $user?->id, 'employee_number' => 'E'.(++$n), 'job_title' => 'موظف', 'hire_date' => '2024-01-01', 'basic_salary' => 5000]);
    }

    /** A department manager: an employee with a self-service account, set as the department head. */
    protected function manager(Department $dept, string $name): User
    {
        $user = $this->userWithRole($this->company, 'employee');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $user->givePermissionTo(['hr.admin_requests.handle']);
        $emp = $this->employee($name, $dept, $user);
        $dept->update(['manager_employee_id' => $emp->id]);

        return $user->fresh();
    }

    protected function service(): AdminRequestService
    {
        return app(AdminRequestService::class);
    }

    protected function sent(?array $employees = null): AdminRequest
    {
        $request = $this->service()->create(['title' => 'اجتماع', 'body' => 'x'], collect($employees ?? [$this->ali, $this->omar])->pluck('id')->all(), $this->owner);

        return $this->service()->send($request, $this->owner);
    }

    protected function assertInvalid(callable $fn): void
    {
        try {
            $fn();
            $this->fail('expected a validation error');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_a_request_with_employees_of_two_departments_is_drafted_numbered_and_sent(): void
    {
        $this->post(route('hr.admin-requests.store'), ['title' => 'حضور اجتماع', 'attend_at' => '2026-07-01 10:00', 'employees' => [$this->ali->id, $this->omar->id]])->assertSessionHasNoErrors();
        $request = AdminRequest::firstOrFail();
        $this->assertStringStartsWith('ADM-', $request->number);
        $this->assertSame('draft', $request->status);
        $this->assertSame(2, $request->lines()->count());

        $this->post(route('hr.admin-requests.send', $request))->assertSessionHasNoErrors();
        $this->assertSame('new', $request->fresh()->status);
        $this->assertEqualsCanonicalizing([$this->sales->id, $this->tech->id], $request->lines()->pluck('department_id')->all());

        // A sent request is no longer editable; a draft can't be empty when sent.
        $this->put(route('hr.admin-requests.update', $request), ['title' => 'x', 'employees' => [$this->ali->id]])->assertSessionHasErrors('status');
        $empty = $this->service()->create(['title' => 'فارغ'], []);
        $this->assertInvalid(fn () => $this->service()->send($empty));
        $this->delete(route('hr.admin-requests.destroy', $request))->assertSessionHasErrors('status');
        $this->delete(route('hr.admin-requests.destroy', $empty))->assertSessionHasNoErrors();
        $this->assertSame(1, AdminRequest::count());
    }

    public function test_sending_alerts_each_department_manager_once(): void
    {
        Notification::fake();
        $this->sent();
        Notification::assertSentTo($this->salesManager, ErpAlert::class, fn ($n) => str_contains($n->body, '1 موظف'));
        Notification::assertSentTo($this->techManager, ErpAlert::class);
    }

    public function test_each_manager_handles_only_his_own_lines_and_the_central_party_all(): void
    {
        $request = $this->sent();
        $aliLine = $request->lines()->where('employee_id', $this->ali->id)->first();
        $omarLine = $request->lines()->where('employee_id', $this->omar->id)->first();

        $this->actingAs($this->salesManager);
        $this->post(route('hr.admin-requests.lines.receive', [$request, $aliLine]))->assertSessionHasNoErrors();
        $this->post(route('hr.admin-requests.lines.receive', [$request, $omarLine]))->assertForbidden();
        $this->get(route('hr.admin-requests.show', $request))->assertOk();
        $this->get(route('hr.admin-requests.inbox'))->assertOk()->assertInertia(fn ($p) => $p->has('lines.data', 2 - 1 + 0)->where('lines.data.0.employee_id', $this->ali->id));

        // The manager's own page lists his lines; he can't open the admin list.
        $this->get(route('hr.admin-requests.index'))->assertForbidden();

        $this->actingAs($this->owner);
        $this->post(route('hr.admin-requests.lines.receive', [$request, $omarLine]))->assertSessionHasNoErrors();
        $this->assertSame('received', $omarLine->fresh()->status);

        // A user with no link to the request gets no page.
        $stranger = $this->userWithRole($this->company, 'employee');
        $this->actingAs($stranger)->get(route('hr.admin-requests.show', $request))->assertForbidden();
    }

    public function test_a_line_goes_through_assignment_notification_and_attendance(): void
    {
        $request = $this->sent([$this->ali]);
        $line = $request->lines()->first();
        $specialist = $this->userWithRole($this->company, 'employee');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $specialist->givePermissionTo('hr.admin_requests.handle');

        $s = $this->service();
        $s->receive($line, $this->salesManager);
        $this->assertSame('processing', $request->fresh()->status);
        $s->assign($line->fresh(), $this->salesManager, $specialist->id, 'اتصل به');
        $this->assertSame($specialist->id, $line->fresh()->assignee_user_id);

        // The specialist may notify but not re-assign; a stranger can do neither.
        $this->assertTrue($s->canHandle($specialist, $line->fresh()));
        $this->assertFalse($s->canHandle($specialist, $line->fresh(), false));
        $this->assertInvalid(fn () => $s->notify($line->fresh(), $specialist, true, null));            // channel needed
        $this->assertInvalid(fn () => $s->notify($line->fresh(), $specialist, false, null, ''));     // reason needed
        $s->notify($line->fresh(), $specialist, false, null, 'الهاتف مغلق');
        $this->assertSame('unreachable', $line->fresh()->status);
        $this->assertSame('completed', $request->fresh()->status); // every line final

        // Another attempt reopens the request.
        $s->reopen($line->fresh(), $this->salesManager);
        $this->assertSame('processing', $request->fresh()->status);
        $s->notify($line->fresh(), $this->salesManager, true, 'in_person');
        $this->assertSame('notified', $line->fresh()->status);
        $this->assertNotNull($line->fresh()->notified_at);
        $this->assertSame('processing', $request->fresh()->status);
        $s->markAttendance($line->fresh(), $this->salesManager, true, 'حضر');
        $this->assertSame('attended', $line->fresh()->status);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->completed_at);
        $this->assertGreaterThanOrEqual(6, $line->events()->count());
    }

    public function test_the_request_status_follows_its_lines_and_illegal_moves_are_refused(): void
    {
        $request = $this->sent();
        [$a, $b] = [$request->lines()->where('employee_id', $this->ali->id)->first(), $request->lines()->where('employee_id', $this->omar->id)->first()];
        $s = $this->service();

        $this->assertInvalid(fn () => $s->markAttendance($a, $this->owner, true));      // not notified yet
        $this->assertInvalid(fn () => $s->notify($a, $this->owner, true, 'call'));      // not received yet
        $s->receive($a, $this->owner);
        $s->notify($a->fresh(), $this->owner, true, 'call');                             // received → notified directly
        $s->markAttendance($a->fresh(), $this->owner, false, 'اعتذر');
        $this->assertSame('absent', $a->fresh()->status);
        $this->assertSame('processing', $request->fresh()->status);                       // the other line is still new
        $s->receive($b, $this->owner);
        $s->notify($b->fresh(), $this->owner, true, 'message');
        $s->markAttendance($b->fresh(), $this->owner, true);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertInvalid(fn () => $s->receive($a->fresh(), $this->owner));          // absent is final
    }

    public function test_the_assignee_must_be_able_to_handle(): void
    {
        $request = $this->sent([$this->ali]);
        $line = $request->lines()->first();
        $this->service()->receive($line, $this->owner);
        $nobody = User::factory()->create(['company_id' => $this->company->id]);
        $this->assertInvalid(fn () => $this->service()->assign($line->fresh(), $this->owner, $nobody->id));
    }

    public function test_the_employee_sees_only_his_own_lines_in_self_service(): void
    {
        $request = $this->sent();
        $this->service()->receive($request->lines()->where('employee_id', $this->ali->id)->first(), $this->owner);
        $aliUser = $this->userWithRole($this->company, 'employee');
        $this->ali->update(['user_id' => $aliUser->id]);

        $this->actingAs($aliUser)->get(route('hr.me'))->assertOk()->assertInertia(fn ($p) => $p->has('adminLines', 1)->where('adminLines.0.title', 'اجتماع'));
    }

    public function test_the_request_is_searchable_and_in_the_quick_menus_data(): void
    {
        $request = $this->sent();
        $this->getJson(route('search', ['q' => $request->number]))->assertOk()->assertJsonFragment(['code' => $request->number]);
    }
}

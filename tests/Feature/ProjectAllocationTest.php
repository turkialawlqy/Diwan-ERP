<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectAllocationRun;
use App\Models\ProjectCost;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\Projects\ProjectAllocationService;
use App\Services\Projects\ProjectReport;
use App\Services\Projects\ProjectService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Indirect cost allocation over projects (revenue / direct cost / hours). */
class ProjectAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $overhead;

    /** @var array<int, Project> */
    protected array $projects = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $customer = Customer::create(['name' => 'وزارة', 'customer_type' => 'government', 'status' => 'active']);
        foreach (['أ', 'ب', 'ج'] as $name) {
            $this->projects[] = app(ProjectService::class)->create(['customer_id' => $customer->id, 'name' => 'مشروع '.$name], [
                ['code' => 'A', 'description' => 'بند', 'unit' => 'm', 'quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 800],
            ]);
        }
        $this->overhead = Account::create(['code' => '59301', 'name_ar' => 'إيجار إداري', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'is_system' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function spend(float $amount): void
    {
        app(PostingService::class)->post(['date' => '2026-06-05', 'description' => 'إيجار المكتب', 'entry_type' => 'manual', 'reference' => 'OH'.$amount, 'lines' => [
            ['account_id' => $this->overhead->id, 'debit' => $amount], ['account_id' => app(AccountMap::class)->id('default_cash'), 'credit' => $amount],
        ]]);
    }

    protected function revenue(Project $project, float $amount): void
    {
        app(PostingService::class)->post(['date' => '2026-06-10', 'description' => 'إيراد', 'entry_type' => 'manual', 'reference' => 'RV'.$project->id, 'lines' => [
            ['account_id' => app(AccountMap::class)->id('default_cash'), 'debit' => $amount], ['role' => 'contract_revenue', 'credit' => $amount, 'cost_center_id' => $project->cost_center_id],
        ]]);
    }

    protected function balance(Account $account): float
    {
        return round((float) $account->fresh()->balance, 2);
    }

    protected function service(): ProjectAllocationService
    {
        return app(ProjectAllocationService::class);
    }

    protected function allocate(string $basis = 'revenue', ?float $amount = null): ProjectAllocationRun
    {
        return $this->service()->post([$this->overhead->id], Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $basis, $amount);
    }

    public function test_revenue_basis_spreads_the_pool_and_keeps_company_profit(): void
    {
        $this->spend(10000);
        foreach ([6000, 3000, 1000] as $i => $revenue) {
            $this->revenue($this->projects[$i], $revenue);
        }

        $preview = $this->service()->preview([$this->overhead->id], Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), 'revenue');
        $this->assertEquals([10000.0, 10000.0], [$preview['pool'], $preview['available']]);

        $run = $this->allocate();
        $this->assertEquals([6000.0, 3000.0, 1000.0], $run->lines->sortByDesc('amount')->pluck('amount')->map(fn ($v) => (float) $v)->values()->all());
        $this->assertSame('ALC-26-00001', $run->number);
        $this->assertSame(0.0, $this->balance($this->overhead), 'the general expense is emptied');
        $this->assertSame(10000.0, $this->balance(Account::find(app(AccountMap::class)->id('project_costs'))), 'and now sits in project cost');

        $this->assertEqualsWithDelta(6000.0, app(ProjectReport::class)->summary($this->projects[0])['actual_cost'], 0.01);
        $this->assertEqualsWithDelta(1000.0, app(ProjectReport::class)->summary($this->projects[2])['actual_cost'], 0.01);
        $this->assertSame('overhead', ProjectCost::where('project_id', $this->projects[0]->id)->value('category'));
        $this->assertNotNull($run->fresh()->journal_entry_id);
    }

    public function test_direct_cost_basis_ignores_earlier_allocations_and_a_second_run_finds_nothing(): void
    {
        $this->spend(900);
        foreach ([100, 200] as $i => $cost) {
            app(ProjectService::class)->recordCost($this->projects[$i], ['category' => 'material', 'date' => '2026-06-12', 'description' => 'مواد', 'amount' => $cost, 'paid_from' => 'accrued']);
        }
        $run = $this->allocate('direct_cost');
        $this->assertEquals([600.0, 300.0], $run->lines->sortByDesc('amount')->pluck('amount')->map(fn ($v) => (float) $v)->values()->all());

        $this->expectException(ValidationException::class);
        $this->service()->preview([$this->overhead->id], Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), 'direct_cost');
    }

    public function test_rounding_never_leaves_a_halala_and_a_partial_amount_is_scaled(): void
    {
        $this->spend(100);
        foreach ($this->projects as $project) {
            $this->revenue($project, 500);
        }
        $run = $this->allocate('revenue');
        $this->assertSame(100.0, round($run->lines->sum(fn ($l) => (float) $l->amount), 2));
        $this->assertEqualsCanonicalizing([33.33, 33.33, 33.34], $run->lines->pluck('amount')->map(fn ($v) => (float) $v)->all());

        $this->spend(200);
        $part = $this->allocate('revenue', 50);
        $this->assertSame(50.0, (float) $part->pool_amount);
        $this->assertSame(150.0, $this->balance($this->overhead));

        $this->expectException(ValidationException::class);
        $this->allocate('revenue', 9999);
    }

    public function test_closed_projects_and_projects_without_the_basis_are_left_out(): void
    {
        $this->spend(1000);
        $this->revenue($this->projects[0], 400);
        $this->revenue($this->projects[1], 600);
        $this->projects[1]->forceFill(['status' => 'closed'])->save();

        $run = $this->allocate('revenue');
        $this->assertSame([$this->projects[0]->id], $run->lines->pluck('project_id')->all());
        $this->assertSame(1000.0, (float) $run->lines->first()->amount);
    }

    public function test_hours_basis_uses_work_order_time_on_projects(): void
    {
        $this->spend(900);
        $emp = Employee::create(['name' => 'فني', 'nationality' => 'SA', 'employee_number' => 'E1', 'job_title' => 'فني', 'hire_date' => '2026-01-01', 'contract_type' => 'unlimited', 'status' => 'active',
            'basic_salary' => 3000, 'total_salary' => 3000, 'department' => 'الصيانة', 'id_type' => 'national_id', 'labour_cost_rate' => 25]);
        foreach ([[0, 60], [1, 120]] as $n => [$i, $minutes]) {
            $wo = DB::table('work_orders')->insertGetId(['company_id' => $this->company->id, 'number' => 'WO-'.$n, 'customer_id' => $this->projects[$i]->customer_id, 'title' => 'عمل', 'project_id' => $this->projects[$i]->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('time_entries')->insert(['company_id' => $this->company->id, 'work_order_id' => $wo, 'employee_id' => $emp->id, 'started_at' => '2026-06-10 08:00:00', 'minutes' => $minutes, 'created_at' => now(), 'updated_at' => now()]);
        }

        $run = $this->allocate('hours');
        $this->assertEquals([600.0, 300.0], $run->lines->sortByDesc('amount')->pluck('amount')->map(fn ($v) => (float) $v)->values()->all());
        $this->assertEquals([2.0, 1.0], $run->lines->sortByDesc('amount')->pluck('basis_value')->map(fn ($v) => (float) $v)->values()->all());
    }

    public function test_reversing_restores_the_expense_and_removes_the_project_cost(): void
    {
        $this->spend(500);
        $this->revenue($this->projects[0], 100);
        $run = $this->allocate();
        $this->assertEqualsWithDelta(500.0, app(ProjectReport::class)->summary($this->projects[0])['actual_cost'], 0.01);

        $this->service()->reverse($run);
        $this->assertSame('reversed', $run->fresh()->status);
        $this->assertSame(500.0, $this->balance($this->overhead));
        $this->assertSame(0, ProjectCost::where('category', 'overhead')->count());
        $this->assertEqualsWithDelta(0.0, app(ProjectReport::class)->summary($this->projects[0])['actual_cost'], 0.01);

        try {
            $this->service()->reverse($run);
            $this->fail('a second reversal must be refused');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        // The pool is whole again and can be allocated once more.
        $this->assertSame('posted', $this->allocate()->status);
    }

    public function test_reversal_is_refused_once_a_charged_project_is_closed(): void
    {
        $this->spend(500);
        $this->revenue($this->projects[0], 100);
        $run = $this->allocate();
        $this->projects[0]->forceFill(['status' => 'closed'])->save();

        $this->expectException(ValidationException::class);
        $this->service()->reverse($run);
    }

    public function test_screens_preview_post_export_and_permissions(): void
    {
        $this->spend(1000);
        $this->revenue($this->projects[0], 100);
        $query = ['preview' => 1, 'account_ids' => [$this->overhead->id], 'period_from' => '2026-06-01', 'period_to' => '2026-06-30', 'basis' => 'revenue'];

        $this->get(route('projects.allocations.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Projects/Allocations/Index'));
        $this->get(route('projects.allocations.create', $query))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Projects/Allocations/Create')->where('preview.pool', 1000)->has('preview.lines', 1));
        $this->get(route('projects.allocations.create', ['account_ids' => [$this->overhead->id], 'preview' => 1, 'basis' => 'direct_cost'] + $query))->assertOk()
            ->assertInertia(fn ($p) => $p->where('previewError', fn ($e) => str_contains($e, 'لا قيمة لمفتاح')));

        $this->post(route('projects.allocations.store'), ['account_ids' => [$this->overhead->id], 'period_from' => '2026-06-01', 'period_to' => '2026-06-30', 'basis' => 'revenue'])->assertSessionHasNoErrors();
        $run = ProjectAllocationRun::firstOrFail();
        $this->get(route('projects.allocations.show', $run->id))->assertOk()->assertInertia(fn ($p) => $p->component('Projects/Allocations/Show')->has('run.lines', 1));
        $this->get(route('reports.export', ['report' => 'project-allocation', 'format' => 'xlsx', 'run_id' => $run->id]))->assertOk();
        $this->delete(route('projects.allocations.destroy', $run->id))->assertSessionHasNoErrors();
        $this->assertSame('reversed', $run->fresh()->status);

        $this->actingAs($this->userWithRole($this->company, 'hr_manager'));
        $this->get(route('projects.allocations.index'))->assertForbidden();
        $this->post(route('projects.allocations.store'), [])->assertForbidden();
    }
}

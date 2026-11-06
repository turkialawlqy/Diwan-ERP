<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PortalUser;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Inventory\StockService;
use App\Services\TenantManager;
use App\Services\WorkOrders\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The technician's phone screen (own orders only) and what the customer sees in the portal. */
class WorkOrdersMobileTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $techUser;

    protected Employee $tech;

    protected Employee $other;

    protected Customer $customer;

    protected Product $part;

    protected WorkOrder $mine;

    protected WorkOrder $notMine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Carbon::setTestNow('2026-06-15 08:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->techUser = $this->userWithRole($this->company);
        $this->actingAs($this->techUser);
        $emp = fn (string $n, ?int $user) => Employee::create(['name' => $n, 'nationality' => 'SA', 'employee_number' => $n, 'job_title' => 'فني', 'hire_date' => '2026-01-01', 'contract_type' => 'unlimited', 'status' => 'active',
            'basic_salary' => 3000, 'total_salary' => 3000, 'department' => 'الصيانة', 'id_type' => 'national_id', 'user_id' => $user, 'labour_cost_rate' => 25]);
        $this->tech = $emp('T1', $this->techUser->id);
        $this->other = $emp('T2', null);
        $this->customer = Customer::create(['name' => 'العميل', 'customer_type' => 'commercial', 'status' => 'active', 'phone' => '0500000000']);
        $type = WorkType::create(['name' => 'تركيب', 'hourly_rate' => 100, 'tax_rate' => 15, 'is_active' => true]);
        $wh = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->part = Product::create(['name_ar' => 'وصلة', 'sku' => 'V1', 'selling_price' => 20, 'purchase_price' => 10, 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $this->part->id, 'warehouse_id' => $wh->id, 'quantity' => 5, 'unit_cost' => 10, 'type' => 'opening']);

        $svc = app(WorkOrderService::class);
        $this->mine = $svc->create(['customer_id' => $this->customer->id, 'title' => 'أمري', 'work_type_id' => $type->id, 'assigned_to' => $this->tech->id, 'scheduled_start' => '2026-06-16 09:00', 'tasks' => ['خطوة']]);
        $this->notMine = $svc->create(['customer_id' => $this->customer->id, 'title' => 'ليس لي', 'work_type_id' => $type->id, 'assigned_to' => $this->other->id, 'scheduled_start' => '2026-06-16 11:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_technician_lists_only_his_orders_and_the_crew_counts(): void
    {
        $this->get(route('work-orders.mine.index'))->assertOk()->assertInertia(fn ($p) => $p->component('WorkOrders/Mine/Index')->has('open', 1)->where('open.0.id', $this->mine->id));

        $this->notMine->crew()->attach($this->tech->id); // now in the crew of the other order
        $this->get(route('work-orders.mine.index'))->assertInertia(fn ($p) => $p->has('open', 2));
    }

    public function test_other_orders_answer_404_on_every_action(): void
    {
        $id = $this->notMine->id;
        $this->get(route('work-orders.mine.show', $id))->assertNotFound();
        $this->post(route('work-orders.mine.start', $id))->assertNotFound();
        $this->post(route('work-orders.mine.part', $id), ['product_id' => $this->part->id, 'quantity' => 1])->assertNotFound();
        $this->post(route('work-orders.mine.complete', $id))->assertNotFound();
        $this->post(route('work-orders.mine.sign', $id), ['name' => 'x', 'signature' => 'y'])->assertNotFound();
        $this->post(route('work-orders.mine.photo', $id), ['file' => UploadedFile::fake()->image('a.jpg')])->assertNotFound();
        $this->assertSame(0, $this->notMine->timeEntries()->count());
    }

    public function test_a_user_without_an_employee_sees_nothing_and_a_role_without_permission_is_refused(): void
    {
        $this->tech->update(['user_id' => null]);
        $this->get(route('work-orders.mine.index'))->assertOk()->assertInertia(fn ($p) => $p->where('hasEmployee', false)->has('open', 0));
        $this->get(route('work-orders.mine.show', $this->mine->id))->assertNotFound();

        $this->actingAs($this->userWithRole($this->company, 'warehouse_keeper'));
        $this->get(route('work-orders.mine.index'))->assertForbidden();
    }

    public function test_the_full_field_visit(): void
    {
        $id = $this->mine->id;
        $this->get(route('work-orders.mine.show', $id))->assertOk()->assertInertia(fn ($p) => $p->component('WorkOrders/Mine/Show')->where('running', null));

        $this->post(route('work-orders.mine.start', $id))->assertSessionHasNoErrors();
        $this->get(route('work-orders.mine.show', $id))->assertInertia(fn ($p) => $p->where('running.work_order_id', $id));
        $this->post(route('work-orders.mine.start', $id))->assertSessionHasErrors('timer');

        $task = $this->mine->tasks()->firstOrFail();
        $this->post(route('work-orders.mine.task', ['order' => $id, 'task' => $task->id]))->assertSessionHasNoErrors();
        $this->assertTrue($task->fresh()->is_done);

        $this->getJson(route('work-orders.mine.products', ['q' => 'وصلة']))->assertOk()->assertJsonPath('data.0.id', $this->part->id);
        $this->getJson(route('work-orders.mine.products'))->assertOk()->assertJsonPath('data', []);   // no term, no results
        $this->post(route('work-orders.mine.part', $id), ['product_id' => $this->part->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->mine->parts()->count());

        $this->post(route('work-orders.mine.photo', $id), ['file' => UploadedFile::fake()->image('site.jpg')])->assertSessionHasNoErrors();
        $this->post(route('work-orders.mine.photo', $id), ['file' => UploadedFile::fake()->create('x.exe', 5)])->assertSessionHasErrors('file');
        $att = $this->mine->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($att->path);

        $png = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat('x', 30));
        $this->post(route('work-orders.mine.sign', $id), ['name' => 'مدير الموقع', 'signature' => $png])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($this->mine->fresh()->customer_signature_path);

        $this->post(route('work-orders.mine.complete', $id))->assertSessionHasErrors('timer'); // the timer still runs
        $this->post(route('work-orders.mine.stop', $id))->assertSessionHasNoErrors();
        $this->post(route('work-orders.mine.complete', $id), ['notes' => 'تم'])->assertRedirect(route('work-orders.mine.index'));
        $this->assertSame('done', $this->mine->fresh()->status);
        $this->post(route('work-orders.mine.start', $id))->assertSessionHasErrors('status'); // closed
    }

    public function test_the_customer_sees_his_orders_in_the_portal_without_internal_data(): void
    {
        $portal = PortalUser::create(['customer_id' => $this->customer->id, 'name' => 'سارة', 'email' => 's@example.test', 'status' => 'active']);
        $theirs = Customer::create(['name' => 'غيري', 'customer_type' => 'commercial', 'status' => 'active']);
        $foreign = app(WorkOrderService::class)->create(['customer_id' => $theirs->id, 'title' => 'عميل آخر']);
        $this->mine->update(['cost_amount' => 777, 'completion_notes' => 'اكتمل']);

        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('portal')->setUser($portal->fresh());
        $url = fn (string $n, array $e = []) => route($n, ['company' => $this->company->id] + $e);

        $this->get($url('customer-portal.work-orders.index'))->assertOk()->assertInertia(fn ($p) => $p->has('orders.data', 2)->where('portal.menu.work_orders', true));
        $res = $this->get($url('customer-portal.work-orders.show', ['order' => $this->mine->id]))->assertOk();
        $this->assertStringNotContainsString('777', $res->getContent());
        $this->assertStringNotContainsString('cost_amount', $res->getContent());
        $this->get($url('customer-portal.work-orders.show', ['order' => $foreign->id]))->assertNotFound();
    }
}

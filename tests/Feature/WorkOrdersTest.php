<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Inventory\StockService;
use App\Services\Support\SlaService;
use App\Services\TenantManager;
use App\Services\WorkOrders\PartsService;
use App\Services\WorkOrders\TimeTracker;
use App\Services\WorkOrders\WorkOrderBilling;
use App\Services\WorkOrders\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Work orders: flow, timers and labour cost, parts from stock, completion rules and billing (cost booked once). */
class WorkOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected Employee $tech;

    protected WorkType $type;

    protected Product $part;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 08:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'العميل', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->tech = Employee::create(['name' => 'فني', 'nationality' => 'SA', 'employee_number' => 'E1', 'job_title' => 'فني', 'hire_date' => '2026-01-01', 'contract_type' => 'unlimited', 'status' => 'active',
            'basic_salary' => 4160, 'total_salary' => 4160, 'department' => 'الصيانة', 'id_type' => 'national_id', 'labour_cost_rate' => 30]);
        $this->type = WorkType::create(['name' => 'صيانة', 'hourly_rate' => 100, 'tax_rate' => 15, 'min_billable_minutes' => 60, 'is_active' => true]);
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->part = Product::create(['name_ar' => 'قطعة', 'sku' => 'P1', 'selling_price' => 80, 'purchase_price' => 50, 'track_inventory' => true, 'is_active' => true, 'tax_rate' => 15]);
        app(StockService::class)->receive(['product_id' => $this->part->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'unit_cost' => 50, 'type' => 'opening']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function service(): WorkOrderService
    {
        return app(WorkOrderService::class);
    }

    protected function order(array $extra = []): WorkOrder
    {
        return $this->service()->create(array_merge(['customer_id' => $this->customer->id, 'title' => 'إصلاح مكيف', 'work_type_id' => $this->type->id, 'assigned_to' => $this->tech->id,
            'scheduled_start' => '2026-06-16 09:00', 'tasks' => ['فحص', 'تنظيف']], $extra));
    }

    protected function onHand(): float
    {
        return app(StockService::class)->onHand($this->part->id, $this->warehouse->id);
    }

    public function test_an_order_gets_a_number_a_status_and_its_checklist(): void
    {
        $o = $this->order();
        $this->assertSame('WO-26-00001', $o->number);
        $this->assertSame('scheduled', $o->status); // a lead and a time
        $this->assertSame(['فحص', 'تنظيف'], $o->tasks->pluck('title')->all());

        $new = $this->order(['assigned_to' => null, 'scheduled_start' => null]);
        $this->assertSame('new', $new->status);
        $this->service()->update($new, ['assigned_to' => $this->tech->id, 'scheduled_start' => '2026-06-17 10:00']);
        $this->assertSame('scheduled', $new->fresh()->status);
    }

    public function test_one_timer_at_a_time_and_stopping_freezes_the_cost(): void
    {
        $a = $this->order();
        $b = $this->order(['title' => 'آخر']);
        $time = app(TimeTracker::class);

        $entry = $time->start($a, $this->tech);
        $this->assertSame('in_progress', $a->fresh()->status);
        try {
            $time->start($b, $this->tech);
            $this->fail('a second timer should be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('timer', $e->errors());
        }

        Carbon::setTestNow('2026-06-15 09:30:00');
        $time->stop($entry);
        $this->assertSame(90, $entry->fresh()->minutes);
        $this->assertEquals(45.0, (float) $entry->fresh()->labour_cost); // 1.5 h × 30

        $time->start($b, $this->tech); // free again
        $this->assertSame(1, $time->closeStale(16) + 1);

        // The same minutes cannot be entered twice for the technician (here: under the timer that ran 08:00–09:30).
        $this->assertValidation(fn () => $time->addManual($a, $this->tech, 30, Carbon::parse('2026-06-15 09:00'), false), 'minutes');
        $manual = $time->addManual($a, $this->tech, 30, Carbon::parse('2026-06-15 07:00'), false, 'تنقل');
        $this->assertFalse($manual->billable);
        $this->assertSame(['minutes' => 120, 'billable_minutes' => 90, 'cost' => 60.0], $time->totals($a->fresh()));
    }

    public function test_a_forgotten_timer_is_closed_and_flagged(): void
    {
        $o = $this->order();
        $time = app(TimeTracker::class);
        $time->start($o, $this->tech);
        Carbon::setTestNow('2026-06-16 10:00:00'); // 26 hours later
        $this->assertSame(1, $time->closeStale(16));
        $e = $o->timeEntries()->firstOrFail();
        $this->assertTrue($e->auto_closed);
        $this->assertSame(16 * 60, $e->minutes);
    }

    public function test_cost_rate_falls_back_to_the_salary(): void
    {
        $this->tech->update(['labour_cost_rate' => null]);
        $this->assertEquals(20.0, app(TimeTracker::class)->costRate($this->tech->fresh())); // 4160 ÷ 208
    }

    public function test_parts_leave_stock_at_cost_and_return_to_it(): void
    {
        $o = $this->order();
        $part = app(PartsService::class)->consume($o, $this->part->id, 2, null, $this->warehouse->id);
        $this->assertSame(8.0, $this->onHand());
        $this->assertEquals(50.0, (float) $part->fresh()->unit_cost);
        $this->assertEquals(80.0, (float) $part->unit_price); // the product's selling price
        $this->assertSame(100.0, app(PartsService::class)->cost($o));

        app(PartsService::class)->giveBack($part);
        $this->assertSame(10.0, $this->onHand());
        $this->assertSame(0.0, app(PartsService::class)->cost($o->fresh()));
        $this->expectException(ValidationException::class);
        app(PartsService::class)->giveBack($part->fresh());
    }

    public function test_completion_needs_stopped_timers_and_the_required_checklist_and_signature(): void
    {
        $o = $this->order();
        $time = app(TimeTracker::class);
        $entry = $time->start($o, $this->tech);

        $this->assertValidation(fn () => $this->service()->complete($o), 'timer');
        $time->stop($entry);

        $this->company->setSetting('work_orders_require_checklist', '1', 'work_orders', 'string');
        $this->assertValidation(fn () => $this->service()->complete($o), 'tasks');
        $o->tasks()->update(['is_done' => true]);

        $this->company->setSetting('work_orders_require_signature', '1', 'work_orders', 'string');
        $this->assertValidation(fn () => $this->service()->complete($o), 'signature');
        $png = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat('x', 20));
        $this->service()->sign($o, 'مدير الموقع', $png);
        $this->assertNotNull($o->fresh()->customer_signature_path);
        // Hours added after signing: the customer signs again.
        app(TimeTracker::class)->addManual($o->fresh(), $this->tech, 15, Carbon::parse('2026-06-15 06:00'));
        $this->assertNull($o->fresh()->customer_signature_path);
        $this->assertValidation(fn () => $this->service()->complete($o->fresh()), 'signature');
        $this->service()->sign($o->fresh(), 'مدير الموقع', $png);

        $done = $this->service()->complete($o->fresh(), 'تم الإصلاح');
        $this->assertSame('done', $done->status);
        $this->assertNotNull($done->completed_at);
    }

    public function test_a_bad_signature_is_refused(): void
    {
        $o = $this->order();
        $this->assertValidation(fn () => $this->service()->sign($o, 'x', 'data:image/png;base64,'.base64_encode('not a png')), 'signature');
        $this->assertValidation(fn () => $this->service()->sign($o, 'x', 'javascript:alert(1)'), 'signature');
    }

    public function test_billing_lines_tax_and_the_cost_booked_once(): void
    {
        $o = $this->order();
        $time = app(TimeTracker::class);
        $entry = $time->start($o, $this->tech);
        Carbon::setTestNow('2026-06-15 08:30:00'); // 30 minutes: the type bills at least 60
        $time->stop($entry);
        app(PartsService::class)->consume($o, $this->part->id, 2, null, $this->warehouse->id); // cost 100, price 160
        $this->assertSame(8.0, $this->onHand());

        $done = $this->service()->complete($o);
        $billing = app(WorkOrderBilling::class);
        $preview = $billing->preview($done);
        $this->assertEquals(260.0, $preview['revenue']);   // 100 labour (60 min minimum) + 160 parts
        $this->assertEquals(115.0, $preview['cost']);      // 15 labour cost (0.5 h × 30) + 100 parts
        $this->assertEquals(145.0, $preview['margin']);

        $invoiced = $billing->invoice($done);
        $this->assertSame('invoiced', $invoiced->status);
        $invoice = Invoice::findOrFail($invoiced->invoice_id);
        $this->assertEquals(260.0, (float) $invoice->subtotal);
        $this->assertEquals(299.0, (float) $invoice->total); // 260 + 15 %
        $this->assertSame($done->number, $invoice->reference_number);

        $this->assertSame(8.0, $this->onHand()); // no second stock issue
        $cogs = Account::find(app(AccountMap::class)->id('cogs'));
        $this->assertEquals(100.0, round((float) $cogs->balance, 2)); // the parts cost, once
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);

        $this->assertValidation(fn () => $billing->invoice($invoiced->fresh()), 'status'); // not twice
        // A second click that read the order before the first finished (still «done» in memory) is refused under the lock.
        $this->assertValidation(fn () => $billing->invoice($done), 'status');
        $this->assertSame(1, Invoice::count());
    }

    public function test_an_order_with_nothing_billable_is_refused_and_a_cancelled_order_returns_its_parts(): void
    {
        $bare = $this->order(['work_type_id' => null]);
        $this->service()->complete($bare);
        $this->assertValidation(fn () => app(WorkOrderBilling::class)->invoice($bare->fresh()), 'status');

        $o = $this->order();
        app(PartsService::class)->consume($o, $this->part->id, 3, null, $this->warehouse->id);
        $this->assertSame(7.0, $this->onHand());
        $this->service()->cancel($o, 'العميل ألغى');
        $this->assertSame(10.0, $this->onHand());
        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertValidation(fn () => $this->service()->cancel($o->fresh()), 'status');
    }

    public function test_completing_resolves_the_linked_ticket_and_the_invoice_is_locked(): void
    {
        $ticket = app(SlaService::class)->open(['customer_id' => $this->customer->id, 'subject' => 'المكيف لا يبرد', 'channel' => 'phone', 'priority' => 'normal']);
        $o = $this->order(['ticket_id' => $ticket->id]);
        $this->assertSame($this->customer->id, $o->customer_id);
        $time = app(TimeTracker::class);
        $time->stop($time->start($o, $this->tech), Carbon::parse('2026-06-15 09:00'));

        $done = $this->service()->complete($o);
        $this->assertSame('resolved', Ticket::find($ticket->id)->status);

        $invoiced = app(WorkOrderBilling::class)->invoice($done);
        $this->delete(route('sales.invoices.destroy', $invoiced->invoice_id))->assertSessionHas('error');
        $this->assertDatabaseHas('invoices', ['id' => $invoiced->invoice_id]);
    }

    public function test_the_reminder_tick_alerts_late_orders_once(): void
    {
        $this->order(['scheduled_start' => '2026-06-15 07:00']); // an hour ago
        $this->assertSame(1, $this->service()->tick()['late']);
        $this->assertSame(0, $this->service()->tick()['late']);
    }

    public function test_office_screens_and_the_module_switch(): void
    {
        $this->get(route('work-orders.index'))->assertOk()->assertInertia(fn ($p) => $p->component('WorkOrders/Index'));
        $this->post(route('work-orders.store'), ['customer_id' => $this->customer->id, 'title' => 'تركيب', 'work_type_id' => $this->type->id, 'tasks' => ['أ', ''], 'crew' => [$this->tech->id]])->assertSessionHasNoErrors();
        $o = WorkOrder::firstOrFail();
        $this->assertSame(1, $o->tasks()->count());
        $this->assertSame(1, $o->crew()->count());
        $this->get(route('work-orders.show', $o->id))->assertOk()->assertInertia(fn ($p) => $p->component('WorkOrders/Show')->has('preview'));
        $this->get(route('work-orders.board', ['date' => '2026-06-16']))->assertOk();
        $this->get(route('work-orders.timesheet'))->assertOk();
        $this->post(route('work-orders.parts.store', $o->id), ['product_id' => $this->part->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(route('work-orders.time.store', $o->id), ['employee_id' => $this->tech->id, 'minutes' => 45])->assertSessionHasNoErrors();
        $this->post(route('work-orders.complete', $o->id), ['notes' => 'تم'])->assertSessionHasNoErrors();
        $this->post(route('work-orders.invoice', $o->id))->assertSessionHasNoErrors();
        $this->assertSame('invoiced', $o->fresh()->status);

        $this->company->setSetting('disabled_modules', ['work_orders'], 'general', 'json');
        $this->get(route('work-orders.index'))->assertForbidden();
    }

    protected function assertValidation(callable $fn, string $key): void
    {
        try {
            $fn();
            $this->fail("expected a validation error on [{$key}]");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }
}

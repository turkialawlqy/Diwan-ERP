<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentMaintenanceSchedule;
use App\Models\EquipmentRental;
use App\Models\Invoice;
use App\Models\MaintenanceOrder;
use App\Models\Product;
use App\Models\ProjectCost;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Equipment\EquipmentReports;
use App\Services\Equipment\MaintenanceService;
use App\Services\Equipment\MaintenanceTracker;
use App\Services\Equipment\ReadingService;
use App\Services\Equipment\RentalBilling;
use App\Services\Inventory\StockService;
use App\Services\Projects\ProjectService;
use App\Services\Saas\AlertService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EquipmentTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Equipment $loader;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-01 09:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->withoutVite();
        $this->actingAs($this->userWithRole($this->company));
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->loader = Equipment::create(['code' => 'LD-01', 'name' => 'شيول كاتربلر', 'meter_type' => 'both', 'current_hours' => 1000, 'current_km' => 20000,
            'hourly_rate' => 150, 'daily_rate' => 1200, 'monthly_rate' => 30000, 'status' => 'available']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find(app(AccountMap::class)->id($role))->balance, 2);
    }

    protected function schedule(array $attributes = []): EquipmentMaintenanceSchedule
    {
        return $this->loader->schedules()->create($attributes + ['name' => 'تغيير زيت المحرك', 'last_done_date' => '2026-06-01', 'last_done_hours' => 1000, 'last_done_km' => 20000]);
    }

    protected function read(string $date, array $data): void
    {
        app(ReadingService::class)->record($this->loader->fresh(), ['date' => $date] + $data);
    }

    public function test_due_is_whichever_interval_comes_first_and_alerts_once(): void
    {
        $oil = $this->schedule(['interval_hours' => 250, 'interval_km' => 5000, 'interval_days' => 90, 'warn_hours' => 25]);

        $this->read('2026-06-02', ['hours_reading' => 1100, 'km_reading' => 20500, 'worked_hours' => 10]);
        $this->assertSame('ok', $oil->fresh()->alert_state);

        $this->read('2026-06-03', ['hours_reading' => 1230, 'worked_hours' => 10]); // 20 h left (warning margin 25)
        $this->assertSame('due_soon', $oil->fresh()->alert_state);
        $this->assertSame(1, auth()->user()->fresh()->notifications()->count());

        $this->read('2026-06-04', ['hours_reading' => 1240, 'worked_hours' => 10]); // still due soon: no second alert
        $this->assertSame(1, auth()->user()->fresh()->notifications()->count());

        // km is far away but hours are past: overdue.
        $this->read('2026-06-05', ['hours_reading' => 1255, 'worked_hours' => 10]);
        $status = app(MaintenanceTracker::class)->status($oil->fresh(), $this->loader->fresh());
        $this->assertSame('overdue', $status['state']);
        $this->assertSame('ok', $status['checks']['km']['state']);
        $this->assertSame(2, auth()->user()->fresh()->notifications()->count());

        // Days alone also trigger.
        $grease = $this->schedule(['name' => 'تشحيم', 'interval_days' => 7, 'warn_days' => 1]);
        Carbon::setTestNow('2026-06-09 09:00:00');
        $this->assertSame('overdue', app(MaintenanceTracker::class)->status($grease->fresh())['state']);
    }

    public function test_readings_must_increase_and_update_the_meters(): void
    {
        $this->read('2026-06-02', ['hours_reading' => 1008, 'km_reading' => 20100]);
        $this->read('2026-06-03', ['hours_reading' => 1017]);

        $loader = $this->loader->fresh();
        $this->assertSame(1017.0, $loader->current_hours);
        $this->assertSame(20100.0, $loader->current_km);
        // Worked hours default to the meter difference.
        $this->assertSame(9.0, $loader->readings()->whereDate('date', '2026-06-03')->first()->worked_hours);

        $this->expectException(ValidationException::class);
        $this->read('2026-06-04', ['hours_reading' => 1010]);
    }

    public function test_storekeeper_issue_reduces_stock_and_posts_maintenance_expense(): void
    {
        $oilProduct = Product::create(['name_ar' => 'زيت 15W40', 'sku' => 'OIL', 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $oilProduct->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'unit_cost' => 20, 'type' => 'opening']);
        $oil = $this->schedule(['interval_hours' => 250, 'product_id' => $oilProduct->id, 'default_quantity' => 18]);
        $filter = $this->schedule(['name' => 'فلتر هواء', 'interval_hours' => 500]);
        $inventoryBefore = $this->balance('inventory');

        $order = app(MaintenanceService::class)->open($this->loader, ['date' => '2026-06-10', 'schedule_ids' => [$oil->id]]);
        $this->assertSame('maintenance', $this->loader->fresh()->status);

        // A storekeeper without the "complete" right can still issue.
        $keeper = $this->userWithRole($this->company, 'warehouse_keeper');
        $this->actingAs($keeper)->post(route('equipment.maintenance.issue', $order), [
            'warehouse_id' => $this->warehouse->id, 'lines' => [['product_id' => $oilProduct->id, 'quantity' => 18, 'schedule_id' => $oil->id]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($keeper)->post(route('equipment.maintenance.complete', $order), ['schedule_ids' => [$oil->id]])->assertForbidden();

        $this->assertSame(82.0, app(StockService::class)->onHand($oilProduct->id, $this->warehouse->id));
        $this->assertSame(360.0, $this->balance('maintenance_expense'));
        $this->assertSame(round($inventoryBefore - 360, 2), $this->balance('inventory'));
        $this->assertSame(360.0, (float) $order->fresh()->parts_cost);

        // Completing resets only the serviced item, at the order's reading.
        $this->read('2026-06-09', ['hours_reading' => 1260, 'worked_hours' => 8]);
        app(MaintenanceService::class)->complete($order->fresh(), [$oil->id], ['hours_reading' => 1262, 'date' => '2026-06-10']);
        $this->assertSame(1262.0, $oil->fresh()->last_done_hours);
        $this->assertSame('ok', $oil->fresh()->alert_state);
        $this->assertSame(1000.0, $filter->fresh()->last_done_hours);
        $this->assertTrue((bool) $order->fresh()->schedules()->first()->pivot->was_overdue);
        $this->assertSame('available', $this->loader->fresh()->status);
    }

    public function test_hourly_customer_rental_with_daily_minimum_is_invoiced_once(): void
    {
        $customer = Customer::create(['name' => 'شركة البناء', 'status' => 'active']);
        $rental = EquipmentRental::create(['number' => 'ER-1', 'type' => 'customer', 'equipment_id' => $this->loader->id, 'customer_id' => $customer->id,
            'billing_basis' => 'hourly', 'rate' => 150, 'min_hours_per_day' => 8, 'tax_rate' => 15, 'start_date' => '2026-06-01', 'status' => 'active']);
        $this->loader->syncStatus();
        $this->assertSame('rented', $this->loader->fresh()->status);

        $this->read('2026-06-02', ['hours_reading' => 1010]);           // 10 h
        $this->read('2026-06-03', ['hours_reading' => 1015]);           // 5 h → billed 8
        $this->read('2026-06-04', ['hours_reading' => 1015, 'worked_hours' => 0]); // standby day → billed 8

        $preview = app(RentalBilling::class)->preview($rental, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        $this->assertSame(15.0, $preview['worked_hours']);
        $this->assertSame(26.0, $preview['quantity']);

        $billing = app(RentalBilling::class)->bill($rental, ['period_from' => '2026-06-01', 'period_to' => '2026-06-30']);
        $invoice = Invoice::find($billing->invoice_id);
        $this->assertSame(3900.0, (float) $invoice->subtotal);
        $this->assertSame(585.0, (float) $invoice->tax_amount);
        $this->assertSame(3900.0, $this->balance('rental_revenue'));
        $this->assertSame('2026-06-30', $rental->fresh()->billed_until->toDateString());

        $this->expectException(ValidationException::class);
        app(RentalBilling::class)->bill($rental, ['period_from' => '2026-06-15', 'period_to' => '2026-07-10']);
    }

    public function test_monthly_rental_prorates_partial_months(): void
    {
        $q = app(RentalBilling::class)->quantity($this->loader, 'monthly', Carbon::parse('2026-06-16'), Carbon::parse('2026-07-31'));
        $this->assertSame(1.5, $q['quantity']);
    }

    public function test_project_assignment_charges_the_project(): void
    {
        $customer = Customer::create(['name' => 'وزارة', 'status' => 'active']);
        $project = app(ProjectService::class)->create(['customer_id' => $customer->id, 'name' => 'طريق'], [
            ['code' => 'A', 'description' => 'حفر', 'unit' => 'm3', 'quantity' => 100, 'unit_price' => 50],
        ]);
        $rental = EquipmentRental::create(['number' => 'ER-2', 'type' => 'project', 'equipment_id' => $this->loader->id, 'project_id' => $project->id,
            'billing_basis' => 'daily', 'rate' => 900, 'tax_rate' => 0, 'start_date' => '2026-06-01', 'end_date' => '2026-06-10', 'status' => 'active']);

        $billing = app(RentalBilling::class)->bill($rental, ['period_from' => '2026-06-01', 'period_to' => '2026-06-30']);

        $this->assertSame(10.0, $billing->quantity); // clipped to the contract
        $cost = ProjectCost::find($billing->project_cost_id);
        $this->assertSame('equipment', $cost->category);
        $this->assertSame(9000.0, (float) $cost->amount);
        $this->assertSame(9000.0, $this->balance('project_costs'));
        $this->assertSame(9000.0, $this->balance('equipment_recovery'));
        $this->assertNull($billing->invoice_id);
    }

    public function test_hired_in_equipment_supplier_bill(): void
    {
        $supplier = Supplier::create(['name' => 'مؤسسة المعدات', 'status' => 'active']);
        $crane = Equipment::create(['code' => 'CR-01', 'name' => 'رافعة', 'ownership' => 'rented_in', 'supplier_id' => $supplier->id, 'supplier_rate' => 20000,
            'supplier_rate_basis' => 'monthly', 'meter_type' => 'hours', 'status' => 'available']);

        app(RentalBilling::class)->supplierBill($crane, ['period_from' => '2026-06-01', 'period_to' => '2026-06-30', 'reference' => 'S-77']);

        $this->assertSame(20000.0, $this->balance('rent_expense'));
        $this->assertSame(3000.0, $this->balance('vat_input'));
        $this->assertSame(23000.0, (float) $supplier->fresh()->current_balance);

        $this->expectException(ValidationException::class);
        app(RentalBilling::class)->supplierBill($crane, ['period_from' => '2026-06-20', 'period_to' => '2026-07-05']);
    }

    public function test_daily_alerts_and_reports(): void
    {
        $part = Product::create(['name_ar' => 'فلتر زيت', 'sku' => 'F1', 'track_inventory' => true, 'is_active' => true]);
        $this->schedule(['interval_hours' => 250, 'product_id' => $part->id, 'default_quantity' => 2]);
        $this->read('2026-06-02', ['hours_reading' => 1300, 'worked_hours' => 3]);

        $kinds = collect(app(AlertService::class)->collect())->pluck('kind');
        $this->assertTrue($kinds->contains('equipment_overdue'));
        $this->assertTrue($kinds->contains('equipment_parts_short'));

        $from = Carbon::parse('2026-06-01');
        $to = Carbon::parse('2026-06-30');
        $reports = app(EquipmentReports::class);
        $util = collect($reports->utilization($from, $to, 10)['rows'])->firstWhere('code', 'LD-01');
        $this->assertSame(1.0, round($util['utilization'], 1)); // 3 h of 300
        $compliance = collect($reports->compliance($from, $to)['rows'])->firstWhere('code', 'LD-01');
        $this->assertSame(1, $compliance['overdue_now']);
        $this->assertIsArray($reports->profitability($from, $to)['rows']);
        $this->assertIsArray($reports->parts($from, $to)['rows']);

        $this->get(route('equipment.reports.index', ['type' => 'utilization', 'from' => '2026-06-01', 'to' => '2026-06-30']))->assertOk();
        $this->get(route('equipment.reports.export', ['type' => 'compliance']))->assertOk();
    }

    public function test_pages_render_and_other_companies_cannot_see_equipment(): void
    {
        $this->schedule(['interval_days' => 30]);
        $order = MaintenanceOrder::create(['number' => 'MO-1', 'equipment_id' => $this->loader->id, 'date' => '2026-06-01']);

        foreach (['equipment.index', 'equipment.readings.index', 'equipment.rentals.index', 'equipment.maintenance.index', 'equipment.maintenance.dashboard'] as $name) {
            $this->get(route($name))->assertOk();
        }
        $this->get(route('equipment.show', $this->loader))->assertOk();
        $this->get(route('equipment.maintenance.show', $order))->assertOk();

        $other = Company::factory()->create();
        app(TenantManager::class)->setTenant($other);
        app(AccountingSetupService::class)->provision($other);
        $this->actingAs($this->userWithRole($other));
        $this->assertSame(0, Equipment::count());
        $this->get(route('equipment.show', $this->loader->id))->assertNotFound();
    }

    public function test_http_flow_create_equipment_schedule_reading_and_order(): void
    {
        Carbon::setTestNow('2026-06-05 09:00:00');
        $this->post(route('equipment.store'), ['code' => 'TR-01', 'name' => 'شاحنة', 'ownership' => 'owned', 'meter_type' => 'km', 'current_km' => 50000])
            ->assertSessionHasNoErrors();
        $truck = Equipment::where('code', 'TR-01')->first();

        $this->post(route('equipment.schedules.store', $truck), ['name' => 'زيت', 'interval_km' => 10000, 'last_done_km' => '', 'last_done_date' => ''])->assertSessionHasNoErrors();
        $this->assertSame(50000.0, $truck->schedules()->first()->last_done_km);
        $this->post(route('equipment.schedules.store', $truck), ['name' => 'بلا فترة'])->assertSessionHasErrors('interval_hours');

        $this->post(route('equipment.readings.store'), ['date' => '2026-06-01', 'rows' => [['equipment_id' => $truck->id, 'km_reading' => 59500]]])
            ->assertSessionHasNoErrors();
        $this->assertSame('due_soon', $truck->schedules()->first()->alert_state);

        $this->post(route('equipment.readings.store'), ['date' => '2026-06-01', 'rows' => [['equipment_id' => $truck->id, 'km_reading' => 59000]]])
            ->assertSessionHasNoErrors(); // correcting the same day's reading
        $this->post(route('equipment.readings.store'), ['date' => '2026-06-01', 'rows' => [['equipment_id' => $truck->id, 'km_reading' => 59500]]])
            ->assertSessionHasNoErrors();
        $this->post(route('equipment.readings.store'), ['date' => '2026-06-02', 'rows' => [['equipment_id' => $truck->id, 'km_reading' => 100]]])
            ->assertSessionHasErrors('rows.0.km_reading');

        $this->post(route('equipment.maintenance.store'), ['equipment_id' => $truck->id, 'type' => 'preventive', 'date' => '2026-06-01'])
            ->assertSessionHasNoErrors();
        $order = MaintenanceOrder::where('equipment_id', $truck->id)->first();
        $this->assertSame(1, $order->schedules()->count()); // the due item is pre-selected
    }
}

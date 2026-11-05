<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bom;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\ProcessStage;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\QcInspection;
use App\Models\QcPlan;
use App\Models\Routing;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WorkCenter;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Inventory\StockService;
use App\Services\Manufacturing\CapacityPlanner;
use App\Services\Manufacturing\MrpService;
use App\Services\Manufacturing\ProcessCostingService;
use App\Services\Manufacturing\ProductionService;
use App\Services\Manufacturing\QualityService;
use App\Services\Manufacturing\SubcontractService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Manufacturing with numbers: job costing, process costing, MRP, quality and subcontracting.
 */
class ManufacturingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Product $a;

    protected Product $b;

    protected Product $fg;

    protected WorkCenter $press;

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
        $this->a = $this->product('خام أ');
        $this->b = $this->product('خام ب');
        $this->fg = $this->product('منتج تام');
        $this->press = WorkCenter::create(['code' => 'PRS', 'name' => 'مكبس', 'type' => 'machine', 'capacity_hours_per_day' => 8, 'efficiency' => 100, 'labour_rate_per_hour' => 60, 'overhead_rate_per_hour' => 30]);
        $this->stock($this->a, 100, 10);
        $this->stock($this->b, 200, 5);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function product(string $name): Product
    {
        return Product::create(['name_ar' => $name, 'sku' => 'P-'.uniqid(), 'selling_price' => 100, 'purchase_price' => 0, 'track_inventory' => true, 'is_active' => true]);
    }

    protected function stock(Product $p, float $qty, float $cost, ?Warehouse $w = null): void
    {
        app(StockService::class)->receive(['product_id' => $p->id, 'warehouse_id' => ($w ?? $this->warehouse)->id, 'quantity' => $qty, 'unit_cost' => $cost, 'type' => 'purchase', 'date' => now()]);
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find(app(AccountMap::class)->id($role))->balance, 2);
    }

    protected function bom(): Bom
    {
        $bom = Bom::create(['product_id' => $this->fg->id, 'name' => 'قائمة المنتج', 'output_quantity' => 1, 'is_active' => true, 'is_default' => true]);
        $bom->items()->create(['component_id' => $this->a->id, 'quantity' => 2]);
        $bom->items()->create(['component_id' => $this->b->id, 'quantity' => 4]);

        return $bom;
    }

    protected function order(float $qty = 10, bool $routing = true): ProductionOrder
    {
        $bom = $this->bom();
        if ($routing) {
            $r = Routing::create(['code' => 'R1', 'name' => 'مسار']);
            $r->operations()->create(['seq' => 10, 'name' => 'كبس', 'work_center_id' => $this->press->id, 'setup_minutes' => 0, 'run_minutes' => 6]);
            $bom->update(['routing_id' => $r->id]);
        }
        $svc = app(ProductionService::class);
        $order = $svc->create(['product_id' => $this->fg->id, 'quantity' => $qty, 'warehouse_id' => $this->warehouse->id, 'planned_start' => '2026-06-01']);

        return $svc->release($order);
    }

    // ------------------------------------------------------------ job costing

    public function test_an_order_plans_its_materials_and_operations_from_the_bom_and_routing(): void
    {
        $order = $this->order(10);
        $this->assertSame([20.0, 40.0], $order->materials->map(fn ($m) => (float) $m->planned_quantity)->all());
        $this->assertSame(60.0, (float) $order->operations->first()->planned_minutes); // 6 min x 10
        $this->assertStringStartsWith('MO-', $order->order_number);
    }

    public function test_materials_labour_and_overhead_end_up_in_finished_goods_at_actual_cost(): void
    {
        $svc = app(ProductionService::class);
        $order = $this->order(10);

        $this->assertSame(400.0, $svc->issueRemaining($order)); // 20 x 10 + 40 x 5
        $this->assertSame(400.0, $this->balance('wip'));
        $svc->logTime($order, ['work_center_id' => $this->press->id, 'operation_id' => $order->operations->first()->id, 'minutes' => 60, 'quantity' => 10]);
        $this->assertSame(490.0, $this->balance('wip')); // + 60 labour + 30 overhead

        $svc->receive($order, ['quantity' => 10, 'date' => '2026-06-02']);
        $order->refresh();
        $this->assertSame(0.0, $this->balance('wip'));
        $this->assertSame('completed', $order->status);
        $this->assertSame(490.0, (float) $order->transferred_cost);
        $this->assertSame(49.0, (float) Product::find($this->fg->id)->average_cost);
        $this->assertSame(10.0, (float) InventoryStock::where('product_id', $this->fg->id)->sum('quantity'));
        $this->assertSame(60.0, $this->balance('applied_labour')); // contra-expense credit
        $this->assertSame(30.0, $this->balance('applied_overhead'));

        $svc->close($order);
        $this->assertSame('closed', $order->refresh()->status);
        $this->assertSame(0.0, (float) $order->variance);
        $this->assertLedgerBalanced();
    }

    public function test_partial_receipts_take_their_share_and_the_last_one_the_rest(): void
    {
        $svc = app(ProductionService::class);
        $order = $this->order(10);
        $svc->issueRemaining($order);
        $svc->logTime($order, ['work_center_id' => $this->press->id, 'minutes' => 60]);

        $svc->receive($order, ['quantity' => 4]);
        $this->assertSame(196.0, (float) $order->refresh()->transferred_cost); // 490 x 4/10
        $this->assertSame('in_progress', $order->status);
        $svc->receive($order, ['quantity' => 6]);
        $this->assertSame(490.0, (float) $order->refresh()->transferred_cost);
        $this->assertSame(0.0, $this->balance('wip'));
    }

    public function test_cost_left_in_wip_becomes_a_variance_when_the_order_is_closed_short(): void
    {
        $svc = app(ProductionService::class);
        $order = $this->order(10);
        $svc->issueRemaining($order);
        $svc->logTime($order, ['work_center_id' => $this->press->id, 'minutes' => 60]);
        $svc->receive($order, ['quantity' => 5]); // half the planned output

        $svc->close($order);
        $order->refresh();
        $this->assertSame(245.0, (float) $order->variance);
        $this->assertSame(245.0, $this->balance('production_variance'));
        $this->assertSame(0.0, $this->balance('wip'));
        $this->assertLedgerBalanced();
    }

    public function test_abnormal_scrap_is_written_off_and_returned_materials_come_back_at_cost(): void
    {
        $svc = app(ProductionService::class);
        $order = $this->order(10, false);
        $svc->issueRemaining($order);
        $svc->returnMaterial($order, $order->materials->first(), 5); // 5 of A back: 50
        $this->assertSame(350.0, (float) $order->refresh()->material_cost);

        $svc->receive($order, ['quantity' => 8, 'scrap_quantity' => 2, 'scrap_abnormal' => true]);
        $this->assertSame(280.0, (float) $order->refresh()->transferred_cost - $this->balance('scrap_loss')); // 8/10 of 350 to stock
        $this->assertSame(70.0, $this->balance('scrap_loss'));
        $this->assertLedgerBalanced();
    }

    public function test_an_order_with_movements_cannot_be_cancelled_and_materials_need_a_release(): void
    {
        $svc = app(ProductionService::class);
        $draft = $svc->create(['product_id' => $this->fg->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id]);
        $this->assertSame('draft', $draft->status);
        $this->expectException(ValidationException::class);
        $svc->release($draft); // no BOM, no routing: nothing to produce from
    }

    public function test_cancel_is_refused_after_materials_were_issued(): void
    {
        $svc = app(ProductionService::class);
        $order = $this->order(10);
        $svc->issueRemaining($order);
        $this->expectException(ValidationException::class);
        $svc->cancel($order);
    }

    public function test_the_order_takes_the_branch_of_its_warehouse(): void
    {
        $branch = Branch::create(['name_ar' => 'الفرع', 'code' => 'B1', 'is_main' => true, 'is_active' => true]);
        $this->warehouse->update(['branch_id' => $branch->id]);
        $order = $this->order(1, false);
        $this->assertSame($branch->id, $order->branch_id);
    }

    // ------------------------------------------------------------ quality

    public function test_a_quality_plan_holds_the_receipt_until_it_is_inspected(): void
    {
        $svc = app(ProductionService::class);
        QcPlan::create(['name' => 'فحص المنتج التام', 'product_id' => $this->fg->id, 'applies_to' => 'production', 'checks' => [['name' => 'الطول', 'type' => 'numeric', 'min' => 9.9, 'max' => 10.1]], 'is_active' => true]);
        $order = $this->order(10, false);
        $svc->issueRemaining($order);

        $receipt = $svc->receive($order, ['quantity' => 10]);
        $this->assertSame('pending_qc', $receipt->status);
        $this->assertSame(0.0, (float) InventoryStock::where('product_id', $this->fg->id)->sum('quantity'));
        $inspection = QcInspection::firstOrFail();

        $quality = app(QualityService::class);
        $this->assertFalse($quality->evaluate($inspection->plan->checks, [0 => 10.5])[0]['passed']);
        $this->assertTrue($quality->evaluate($inspection->plan->checks, [0 => 10.0])[0]['passed']);

        $quality->complete($inspection, ['accepted' => 8, 'rejected' => 2, 'disposition' => 'scrap']);
        $this->assertSame('partial', $inspection->refresh()->result);
        $this->assertSame(8.0, (float) InventoryStock::where('product_id', $this->fg->id)->sum('quantity'));
        $this->assertSame(80.0, $this->balance('scrap_loss')); // 2 of 10 x 400
        $this->assertSame(0.0, $this->balance('wip'));
        $this->assertLedgerBalanced();
    }

    // ------------------------------------------------------------ process costing

    public function test_weighted_average_equivalent_units(): void
    {
        $oil = $this->product('زيت خام');
        $this->stock($oil, 900, 10);
        $stage = ProcessStage::create(['code' => 'S1', 'name' => 'العجن', 'sequence' => 1, 'product_id' => $this->fg->id, 'warehouse_id' => $this->warehouse->id]);
        $svc = app(ProcessCostingService::class);

        $period = $svc->openPeriod($stage, '2026-06-01', '2026-06-30');
        $svc->issueMaterials($period, [['product_id' => $oil->id, 'quantity' => 900]]); // 9,000
        $svc->addConversion($period, $this->press->id, 4200 / 90 * 60); // 4,200 / 90 per hour = 46.67h -> 4,200
        $period = $svc->close($period->refresh(), ['units_started' => 1000, 'units_completed' => 800, 'units_closing_wip' => 200, 'closing_material_pct' => 100, 'closing_conversion_pct' => 50]);

        $this->assertSame(1000.0, (float) $period->eu_mat);
        $this->assertSame(900.0, (float) $period->eu_conv);
        $this->assertSame(9.0, (float) $period->cpu_mat);
        $this->assertEqualsWithDelta(4.666667, (float) $period->cpu_conv, 0.000001);
        $this->assertSame(10933.33, (float) $period->transferred_out); // 800 x (9 + 4.6667)
        $this->assertSame(1800.0, (float) $period->closing_mat);
        $this->assertSame(466.67, (float) $period->closing_conv);
        $this->assertSame(800.0, (float) InventoryStock::where('product_id', $this->fg->id)->sum('quantity'));
        $this->assertEqualsWithDelta(2266.67, $this->balance('wip'), 0.01); // what stays in process
        $this->assertLedgerBalanced();

        // The next period starts from the closing work in progress.
        $next = $svc->openPeriod($stage, '2026-07-01', '2026-07-31');
        $this->assertSame(200.0, (float) $next->units_opening_wip);
        $this->assertSame(1800.0, (float) $next->opening_mat);
    }

    public function test_a_stage_passes_its_cost_on_to_the_next(): void
    {
        $s1 = ProcessStage::create(['code' => 'S1', 'name' => 'القطع', 'sequence' => 1, 'product_id' => $this->fg->id, 'warehouse_id' => $this->warehouse->id]);
        $s2 = ProcessStage::create(['code' => 'S2', 'name' => 'التعبئة', 'sequence' => 2, 'product_id' => $this->fg->id, 'previous_stage_id' => $s1->id, 'warehouse_id' => $this->warehouse->id]);
        $svc = app(ProcessCostingService::class);

        $p1 = $svc->openPeriod($s1, '2026-06-01', '2026-06-30');
        $svc->issueMaterials($p1, [['product_id' => $this->a->id, 'quantity' => 50]]); // 500
        try {
            $svc->close($p1->refresh(), ['units_started' => 100, 'units_completed' => 100, 'units_closing_wip' => 0, 'closing_material_pct' => 0, 'closing_conversion_pct' => 0]);
            $this->fail('the next stage needs an open period to receive the units');
        } catch (ValidationException) {
        }
        $p2 = $svc->openPeriod($s2, '2026-06-01', '2026-06-30');
        $svc->close($p1->refresh(), ['units_started' => 100, 'units_completed' => 100, 'units_closing_wip' => 0, 'closing_material_pct' => 0, 'closing_conversion_pct' => 0]);
        $this->assertSame(500.0, (float) $p2->refresh()->added_ti);
        $p2 = $svc->close($p2, ['units_started' => 100, 'units_completed' => 100, 'units_closing_wip' => 0, 'closing_material_pct' => 0, 'closing_conversion_pct' => 0]);
        $this->assertSame(500.0, (float) $p2->transferred_out);
        $this->assertSame(0.0, $this->balance('wip'));
        $this->assertSame(100.0, (float) InventoryStock::where('product_id', $this->fg->id)->sum('quantity'));
    }

    // ------------------------------------------------------------ MRP and capacity

    public function test_mrp_adds_the_bom_scrap_percentage_to_component_needs(): void
    {
        $bom = $this->bom();
        $bom->update(['scrap_percent' => 10]);
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
        $so = DB::table('sales_orders')->insertGetId(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'order_number' => 'SO-2', 'order_date' => '2026-06-01', 'delivery_date' => '2026-06-10', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_order_items')->insert(['company_id' => $this->company->id, 'sales_order_id' => $so, 'product_id' => $this->fg->id, 'item_name' => 'منتج', 'quantity' => 40, 'delivered_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        InventoryStock::where('product_id', $this->a->id)->update(['quantity' => 0]);

        $byProduct = app(MrpService::class)->run(30)->suggestions()->get()->keyBy('product_id');
        $this->assertSame(88.0, (float) $byProduct[$this->a->id]->quantity); // 40 x 2 x 1.10
    }

    public function test_mrp_explodes_a_shortage_through_the_bom(): void
    {
        $this->bom();
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
        $so = DB::table('sales_orders')->insertGetId(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'order_number' => 'SO-1', 'order_date' => '2026-06-01', 'delivery_date' => '2026-06-10', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_order_items')->insert(['company_id' => $this->company->id, 'sales_order_id' => $so, 'product_id' => $this->fg->id, 'item_name' => 'منتج', 'quantity' => 50, 'delivered_quantity' => 10, 'created_at' => now(), 'updated_at' => now()]);

        $run = app(MrpService::class)->run(30);
        $byProduct = $run->suggestions()->get()->keyBy('product_id');

        $this->assertSame('manufacture', $byProduct[$this->fg->id]->type);
        $this->assertSame(40.0, (float) $byProduct[$this->fg->id]->quantity); // 50 ordered - 10 delivered
        // 40 units need 80 of A (100 in stock: enough) and 160 of B (200 in stock: enough).
        $this->assertArrayNotHasKey($this->a->id, $byProduct->all());
        $this->assertArrayNotHasKey($this->b->id, $byProduct->all());

        // With less stock the shortage turns into purchases.
        InventoryStock::where('product_id', $this->a->id)->update(['quantity' => 30]);
        $second = app(MrpService::class)->run(30)->suggestions()->get()->keyBy('product_id');
        $this->assertSame('purchase', $second[$this->a->id]->type);
        $this->assertSame(50.0, (float) $second[$this->a->id]->quantity); // 80 needed - 30 on hand

        $request = app(MrpService::class)->convertToPurchaseRequest([$second[$this->a->id]->id]);
        $this->assertSame(50.0, (float) $request->items()->first()->quantity);
        $this->assertSame('converted', $second[$this->a->id]->refresh()->status);

        $order = app(MrpService::class)->convertToProductionOrder($second[$this->fg->id], $this->warehouse->id);
        $this->assertSame(40.0, (float) $order->quantity);
    }

    public function test_capacity_load_counts_open_operations_per_week(): void
    {
        $this->order(30); // 6 min x 30 = 180 minutes at the press
        $rows = app(CapacityPlanner::class)->load(4, Carbon::parse('2026-06-01'));
        $press = collect($rows)->firstWhere('code', 'PRS');
        $this->assertSame(2400.0, $press['weekly_capacity']); // 480 min x 5 days
        $this->assertSame(180.0, collect($press['weeks'])->sum('load'));
    }

    // ------------------------------------------------------------ subcontracting

    public function test_subcontracted_goods_cost_materials_plus_service(): void
    {
        $this->bom();
        $vendor = Warehouse::create(['name_ar' => 'عند المقاول', 'code' => 'V1', 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'مصنع خارجي', 'status' => 'active']);
        $svc = app(SubcontractService::class);

        $order = $svc->create(['supplier_id' => $supplier->id, 'product_id' => $this->fg->id, 'quantity' => 10, 'service_price' => 7, 'source_warehouse_id' => $this->warehouse->id,
            'vendor_warehouse_id' => $vendor->id, 'output_warehouse_id' => $this->warehouse->id, 'order_date' => '2026-06-01']);
        $svc->send($order);
        $this->assertSame(20.0, (float) InventoryStock::where('product_id', $this->a->id)->where('warehouse_id', $vendor->id)->sum('quantity'));

        $svc->receive($order->refresh(), 4);
        $order->refresh();
        $this->assertSame('partially_received', $order->status);
        $svc->receive($order, 6);
        $this->assertSame('completed', $order->refresh()->status);

        // 10 units: materials 400 + service 70, FG valued at 47 each, payables carry the service.
        $this->assertSame(47.0, (float) Product::find($this->fg->id)->average_cost);
        $this->assertSame(10.0, (float) InventoryStock::where('product_id', $this->fg->id)->sum('quantity'));
        $this->assertSame(0.0, (float) InventoryStock::where('warehouse_id', $vendor->id)->sum('quantity'));
        $this->assertSame(70.0, $this->balance('payables'));
        $this->assertLedgerBalanced();
    }

    protected function assertLedgerBalanced(): void
    {
        $totals = DB::table('account_balances')->where('company_id', $this->company->id)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertEqualsWithDelta((float) $totals->d, (float) $totals->c, 0.001, 'Ledger is not balanced');
    }
}

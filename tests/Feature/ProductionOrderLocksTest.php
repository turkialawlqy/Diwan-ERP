<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\Warehouse;
use App\Models\WorkCenter;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Inventory\StockService;
use App\Services\Manufacturing\ProductionService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\ObservesRowLocks;
use Tests\TestCase;

/** Two operators on one production order go one after the other, and each sees what the other did. */
class ProductionOrderLocksTest extends TestCase
{
    use ObservesRowLocks, RefreshDatabase;

    protected Warehouse $warehouse;

    protected ProductionService $production;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->production = app(ProductionService::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function order(float $quantity = 10, float $perUnit = 1): ProductionOrder
    {
        $raw = Product::create(['name_ar' => 'خام', 'sku' => 'R-'.uniqid(), 'selling_price' => 1, 'track_inventory' => true, 'is_active' => true]);
        $finished = Product::create(['name_ar' => 'منتج', 'sku' => 'F-'.uniqid(), 'selling_price' => 1, 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $raw->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'unit_cost' => 10, 'type' => 'opening', 'date' => now()]);
        $bom = Bom::create(['product_id' => $finished->id, 'name' => 'قائمة', 'output_quantity' => 1, 'is_active' => true, 'is_default' => true]);
        $bom->items()->create(['component_id' => $raw->id, 'quantity' => $perUnit]);

        return $this->production->release($this->production->create(['product_id' => $finished->id, 'quantity' => $quantity, 'warehouse_id' => $this->warehouse->id]));
    }

    public function test_material_issued_from_a_stale_screen_adds_to_what_was_issued_meanwhile(): void
    {
        $order = $this->order();
        $material = $order->materials()->firstOrFail();
        [$first, $second] = [ProductionOrderMaterial::findOrFail($material->id), ProductionOrderMaterial::findOrFail($material->id)];

        $this->production->issueMaterial(ProductionOrder::findOrFail($order->id), $first, 5);
        $this->production->issueMaterial(ProductionOrder::findOrFail($order->id), $second, 5);

        $this->assertEqualsWithDelta(10, (float) $material->fresh()->issued_quantity, 0.001);
        $this->assertEqualsWithDelta(100, (float) $material->fresh()->cost, 0.01);
        $this->assertEqualsWithDelta(100, (float) $order->fresh()->material_cost, 0.01);
    }

    public function test_closing_from_a_stale_screen_is_refused_and_posts_nothing(): void
    {
        $order = $this->order();
        $this->production->issueRemaining($order);
        $this->production->receive($order->fresh(), ['quantity' => 5]);
        $stale = ProductionOrder::findOrFail($order->id);
        $this->production->close($order->fresh());
        $entries = JournalEntry::count();

        try {
            $this->production->close($stale);
            $this->fail('A closed order was closed again.');
        } catch (ValidationException) {
            $this->assertSame($entries, JournalEntry::count());
        }
    }

    public function test_issuing_the_rest_from_a_stale_screen_finds_nothing_left(): void
    {
        $order = $this->order();
        $stale = ProductionOrder::findOrFail($order->id);

        $this->assertEqualsWithDelta(100, $this->production->issueRemaining($order), 0.01);
        $this->assertEqualsWithDelta(0, $this->production->issueRemaining($stale), 0.01);
        $this->assertEqualsWithDelta(10, (float) $order->materials()->firstOrFail()->issued_quantity, 0.001);
    }

    public function test_every_change_starts_by_locking_the_order(): void
    {
        $firstLock = fn (callable $work) => $this->tablesLockedBy($work)[0] ?? null;
        $order = $this->order();
        $material = $order->materials()->firstOrFail();
        $center = WorkCenter::create(['code' => 'C1', 'name' => 'مركز', 'type' => 'machine', 'capacity_hours_per_day' => 8, 'efficiency' => 100, 'labour_rate_per_hour' => 60, 'overhead_rate_per_hour' => 0]);

        $this->assertSame('production_orders', $firstLock(fn () => $this->production->issueMaterial($order->fresh(), $material, 2)));
        $this->assertSame('production_orders', $firstLock(fn () => $this->production->returnMaterial($order->fresh(), $material->fresh(), 1)));
        $this->assertSame('production_orders', $firstLock(fn () => $this->production->logTime($order->fresh(), ['work_center_id' => $center->id, 'minutes' => 60])));
        $this->assertSame('production_orders', $firstLock(fn () => $this->production->receive($order->fresh(), ['quantity' => 1])));
        $this->assertSame('production_orders', $firstLock(fn () => $this->production->close($order->fresh())));

        $draft = $this->order();
        $this->assertSame('production_orders', $firstLock(fn () => $this->production->cancel($draft->fresh())));
    }
}

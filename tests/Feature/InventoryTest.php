<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bom;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Inventory\StockService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Warehouse $main;

    protected Warehouse $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');

        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        $owner = $this->userWithRole($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($owner);

        $this->main = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->branch = Warehouse::create(['name_ar' => 'فرع', 'code' => 'W2', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function product(array $attributes = []): Product
    {
        return Product::create(array_merge(['name_ar' => 'صنف', 'sku' => 'P-'.uniqid(), 'selling_price' => 100, 'purchase_price' => 0, 'track_inventory' => true, 'is_active' => true], $attributes));
    }

    protected function stock(): StockService
    {
        return app(StockService::class);
    }

    protected function balance(string $role): float
    {
        return (float) Account::find(app(AccountMap::class)->id($role))->balance;
    }

    public function test_moving_average_cost_and_issue_cost(): void
    {
        $p = $this->product();
        $this->stock()->receive(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 10, 'unit_cost' => 10, 'type' => 'purchase']);
        $this->stock()->receive(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 10, 'unit_cost' => 20, 'type' => 'purchase']);

        $this->assertSame(15.0, (float) $p->fresh()->average_cost);

        $cost = $this->stock()->issue(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 4, 'type' => 'sale']);
        $this->assertSame(60.0, $cost);
        $this->assertSame(16.0, $this->stock()->onHand($p->id));
        $this->assertSame(3, StockMovement::where('product_id', $p->id)->count());
    }

    public function test_fifo_costing(): void
    {
        $this->company->setSetting('costing_method', 'fifo');
        $p = $this->product();
        $this->stock()->receive(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 5, 'unit_cost' => 10, 'type' => 'purchase']);
        $this->stock()->receive(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 5, 'unit_cost' => 30, 'type' => 'purchase']);

        // 5 at 10 + 2 at 30
        $this->assertSame(110.0, $this->stock()->issue(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 7, 'type' => 'sale']));
    }

    public function test_negative_stock_is_blocked_unless_allowed(): void
    {
        $p = $this->product();

        try {
            $this->stock()->issue(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 1, 'type' => 'sale']);
            $this->fail('Negative stock allowed');
        } catch (ValidationException) {
        }

        $this->company->setSetting('allow_negative_stock', '1', 'inventory', 'boolean');
        $this->stock()->issue(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 1, 'type' => 'sale']);
        $this->assertSame(-1.0, $this->stock()->onHand($p->id));
    }

    public function test_batches_are_issued_fefo_and_serials_tracked(): void
    {
        $p = $this->product(['tracking' => 'batch']);
        $this->stock()->receive(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 5, 'unit_cost' => 1, 'type' => 'purchase', 'batch_number' => 'LATE', 'expiry_date' => '2027-12-31']);
        $this->stock()->receive(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 5, 'unit_cost' => 1, 'type' => 'purchase', 'batch_number' => 'SOON', 'expiry_date' => '2026-08-01']);

        $this->stock()->issue(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 7, 'type' => 'sale']);

        $this->assertSame(0.0, (float) ProductBatch::where('batch_number', 'SOON')->value('quantity'));
        $this->assertSame(3.0, (float) ProductBatch::where('batch_number', 'LATE')->value('quantity'));

        $s = $this->product(['tracking' => 'serial']);
        $this->stock()->receive(['product_id' => $s->id, 'warehouse_id' => $this->main->id, 'quantity' => 2, 'unit_cost' => 500, 'type' => 'purchase', 'serials' => ['SN1', 'SN2']]);
        $this->stock()->issue(['product_id' => $s->id, 'warehouse_id' => $this->main->id, 'quantity' => 1, 'type' => 'sale', 'serials' => ['SN2']]);

        $this->assertSame('sold', ProductSerial::where('serial_number', 'SN2')->value('status'));
        $this->assertSame('in_stock', ProductSerial::where('serial_number', 'SN1')->value('status'));

        $this->expectException(ValidationException::class);
        $this->stock()->issue(['product_id' => $s->id, 'warehouse_id' => $this->main->id, 'quantity' => 1, 'type' => 'sale', 'serials' => ['SN2']]);
    }

    public function test_kit_issues_components(): void
    {
        $a = $this->product();
        $b = $this->product();
        $kit = $this->product(['is_kit' => true, 'track_inventory' => true]);
        $kit->kitItems()->create(['component_id' => $a->id, 'quantity' => 2]);
        $kit->kitItems()->create(['component_id' => $b->id, 'quantity' => 1]);

        $this->stock()->receive(['product_id' => $a->id, 'warehouse_id' => $this->main->id, 'quantity' => 10, 'unit_cost' => 5, 'type' => 'purchase']);
        $this->stock()->receive(['product_id' => $b->id, 'warehouse_id' => $this->main->id, 'quantity' => 10, 'unit_cost' => 7, 'type' => 'purchase']);

        $cost = $this->stock()->issue(['product_id' => $kit->id, 'warehouse_id' => $this->main->id, 'quantity' => 3, 'type' => 'sale']);

        $this->assertSame(51.0, $cost); // 3*(2*5 + 7)
        $this->assertSame(4.0, $this->stock()->onHand($a->id));
        $this->assertSame(7.0, $this->stock()->onHand($b->id));
    }

    public function test_purchase_and_sale_documents_move_stock_and_post_cost(): void
    {
        $p = $this->product();
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);

        $this->post(route('purchases.invoices.store'), [
            'supplier_id' => $supplier->id, 'warehouse_id' => $this->main->id, 'invoice_date' => '2026-06-15',
            'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'items' => [['product_id' => $p->id, 'item_name' => 'صنف', 'quantity' => 10, 'unit_price' => 50, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10.0, $this->stock()->onHand($p->id, $this->main->id));
        $this->assertSame(50.0, (float) $p->fresh()->average_cost);
        $this->assertSame(500.0, $this->balance('inventory'));

        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->post(route('sales.invoices.store'), [
            'customer_id' => $customer->id, 'warehouse_id' => $this->main->id, 'invoice_date' => '2026-06-15',
            'payment_method' => 'cash', 'payment_status' => 'paid',
            'items' => [['product_id' => $p->id, 'item_name' => 'صنف', 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(7.0, $this->stock()->onHand($p->id, $this->main->id));
        $this->assertSame(150.0, $this->balance('cogs'));
        $this->assertSame(350.0, $this->balance('inventory'));

        // Selling more than available is refused.
        $this->post(route('sales.invoices.store'), [
            'customer_id' => $customer->id, 'warehouse_id' => $this->main->id, 'invoice_date' => '2026-06-15',
            'payment_method' => 'cash', 'payment_status' => 'paid',
            'items' => [['product_id' => $p->id, 'item_name' => 'صنف', 'quantity' => 50, 'unit_price' => 100, 'tax_rate' => 15]],
        ])->assertSessionHasErrors('items');
    }

    public function test_two_step_transfer_with_shortage(): void
    {
        $p = $this->product();
        $this->stock()->receive(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 10, 'unit_cost' => 20, 'type' => 'purchase']);

        $this->post(route('inventory.stock-transfers.store'), [
            'from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $this->branch->id, 'transfer_date' => '2026-06-15',
            'items' => [['product_id' => $p->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();
        $transfer = StockTransfer::firstOrFail();

        $this->post(route('inventory.stock-transfers.send', $transfer->id))->assertSessionHasNoErrors();
        $this->assertSame(6.0, $this->stock()->onHand($p->id, $this->main->id));
        $this->assertSame(80.0, $this->balance('goods_in_transit'));

        $item = $transfer->items()->first();
        $this->post(route('inventory.stock-transfers.receive', $transfer->id), ['received' => [$item->id => 3]])->assertSessionHasNoErrors();

        $this->assertSame(3.0, $this->stock()->onHand($p->id, $this->branch->id));
        $this->assertSame(0.0, $this->balance('goods_in_transit'));
        $this->assertSame(20.0, $this->balance('inventory_adjustment'));
    }

    public function test_stock_count_document(): void
    {
        $p = $this->product();
        $this->stock()->receive(['product_id' => $p->id, 'warehouse_id' => $this->main->id, 'quantity' => 10, 'unit_cost' => 10, 'type' => 'purchase']);

        $this->post(route('inventory.counts.store'), ['warehouse_id' => $this->main->id, 'count_date' => '2026-06-15', 'count_type' => 'full'])->assertSessionHasNoErrors();
        $count = StockCount::firstOrFail();
        $this->assertSame(10.0, (float) $count->items()->where('product_id', $p->id)->value('system_quantity'));

        $this->put(route('inventory.counts.update', $count->id), ['lines' => [['product_id' => $p->id, 'counted_quantity' => 8]]])->assertSessionHasNoErrors();
        $this->post(route('inventory.counts.approve', $count->id))->assertSessionHasNoErrors();

        $this->assertSame(8.0, $this->stock()->onHand($p->id, $this->main->id));
        $this->assertSame(20.0, $this->balance('inventory_adjustment'));
        $this->assertSame('approved', $count->fresh()->status);
    }

    public function test_assembly_and_reports_render(): void
    {
        $component = $this->product();
        $finished = $this->product(['name_ar' => 'منتج تام']);
        $this->stock()->receive(['product_id' => $component->id, 'warehouse_id' => $this->main->id, 'quantity' => 20, 'unit_cost' => 4, 'type' => 'purchase']);

        $this->post(route('inventory.assembly.boms.store'), [
            'product_id' => $finished->id, 'name' => 'تركيبة', 'output_quantity' => 1,
            'items' => [['component_id' => $component->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->post(route('inventory.assembly.orders.store'), [
            'bom_id' => Bom::first()->id, 'warehouse_id' => $this->main->id, 'quantity' => 5, 'date' => '2026-06-15',
        ])->assertSessionHasNoErrors();

        $this->assertSame(10.0, $this->stock()->onHand($component->id));
        $this->assertSame(5.0, $this->stock()->onHand($finished->id));
        $this->assertSame(8.0, (float) $finished->fresh()->average_cost);

        foreach (['inventory.reports.valuation', 'inventory.reports.reorder', 'inventory.reports.slow-moving', 'inventory.reports.expiry', 'inventory.stock-transfers.index', 'inventory.counts.index', 'inventory.assembly.index'] as $name) {
            $this->get(route($name))->assertOk();
        }
        $this->get(route('inventory.reports.item-card', ['product_id' => $component->id]))->assertOk();
        $this->get(route('inventory.products.show', $component->id))->assertOk();
        $this->get(route('inventory.reports.valuation', ['as_of' => '2026-06-14']))->assertOk();
    }

    public function test_products_list_counts_low_stock_per_product(): void
    {
        $low = $this->product(['min_stock_alert' => 10]);
        $this->stock()->receive(['product_id' => $low->id, 'warehouse_id' => $this->main->id, 'quantity' => 4, 'unit_cost' => 10, 'type' => 'purchase']);
        $this->stock()->receive(['product_id' => $low->id, 'warehouse_id' => $this->branch->id, 'quantity' => 4, 'unit_cost' => 10, 'type' => 'purchase']);
        $healthy = $this->product();
        $this->stock()->receive(['product_id' => $healthy->id, 'warehouse_id' => $this->main->id, 'quantity' => 50, 'unit_cost' => 10, 'type' => 'purchase']);
        $this->product(); // never stocked: counts as zero
        $this->product(['track_inventory' => false]); // services are ignored

        $this->get(route('inventory.products.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.low_stock_count', 2));
    }
}

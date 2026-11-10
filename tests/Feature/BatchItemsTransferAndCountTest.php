<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Inventory\StockService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Batch items move and are counted without anyone having to name a batch; where a batch cannot be known, it is chosen safely. */
class BatchItemsTransferAndCountTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $from;

    protected Warehouse $to;

    protected StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $this->from = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->to = Warehouse::create(['name_ar' => 'فرع', 'code' => 'W2', 'is_active' => true]);
        $this->stock = app(StockService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function product(string $tracking = 'batch'): Product
    {
        return Product::create(['name_ar' => 'صنف', 'sku' => 'P-'.uniqid(), 'selling_price' => 100, 'purchase_price' => 0, 'track_inventory' => true, 'tracking' => $tracking, 'is_active' => true]);
    }

    protected function batchIn(Product $product, string $number, float $quantity, ?string $expiry, ?Warehouse $warehouse = null): void
    {
        $this->stock->receive(['product_id' => $product->id, 'warehouse_id' => ($warehouse ?? $this->from)->id, 'quantity' => $quantity, 'unit_cost' => 10, 'type' => 'opening', 'date' => now(), 'batch_number' => $number, 'expiry_date' => $expiry]);
    }

    protected function batch(Product $product, Warehouse $warehouse, string $number): ?ProductBatch
    {
        return ProductBatch::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->where('batch_number', $number)->first();
    }

    public function test_an_immediate_transfer_with_no_batch_chosen_moves_the_earliest_expiring_batches(): void
    {
        $product = $this->product();
        $this->batchIn($product, 'LATER', 5, '2027-06-01');
        $this->batchIn($product, 'SOONER', 2, '2026-09-01');

        $cost = $this->stock->transfer($product->id, $this->from->id, $this->to->id, 4, ['date' => now(), 'source_type' => 'manual_transfer']);

        $this->assertEqualsWithDelta(40, $cost, 0.01);
        $this->assertEqualsWithDelta(2, (float) $this->batch($product, $this->to, 'SOONER')->quantity, 0.001);
        $this->assertSame('2026-09-01', $this->batch($product, $this->to, 'SOONER')->expiry_date->toDateString());
        $this->assertEqualsWithDelta(2, (float) $this->batch($product, $this->to, 'LATER')->quantity, 0.001);
        $this->assertEqualsWithDelta(0, (float) $this->batch($product, $this->from, 'SOONER')->quantity, 0.001);
        $this->assertEqualsWithDelta(3, (float) $this->batch($product, $this->from, 'LATER')->quantity, 0.001);
        $this->assertEqualsWithDelta(3, $this->stock->onHand($product->id, $this->from->id), 0.001);
        $this->assertEqualsWithDelta(4, $this->stock->onHand($product->id, $this->to->id), 0.001);
    }

    public function test_a_chosen_batch_is_still_the_one_that_moves(): void
    {
        $product = $this->product();
        $this->batchIn($product, 'LATER', 5, '2027-06-01');
        $this->batchIn($product, 'SOONER', 2, '2026-09-01');
        $later = $this->batch($product, $this->from, 'LATER');

        $this->stock->transfer($product->id, $this->from->id, $this->to->id, 3, ['date' => now(), 'batch_id' => $later->id, 'source_type' => 'manual_transfer']);

        $this->assertNull($this->batch($product, $this->to, 'SOONER'));
        $this->assertEqualsWithDelta(3, (float) $this->batch($product, $this->to, 'LATER')->quantity, 0.001);
    }

    public function test_a_transfer_beyond_what_the_batches_hold_is_refused_and_moves_nothing(): void
    {
        $product = $this->product();
        $this->batchIn($product, 'ONLY', 2, '2027-01-01');

        try {
            $this->stock->transfer($product->id, $this->from->id, $this->to->id, 3, ['date' => now(), 'source_type' => 'manual_transfer']);
            $this->fail('A transfer of more than the batches hold went through.');
        } catch (ValidationException) {
            $this->assertEqualsWithDelta(2, $this->stock->onHand($product->id, $this->from->id), 0.001);
            $this->assertNull($this->batch($product, $this->to, 'ONLY'));
        }
    }

    public function test_a_two_step_transfer_with_no_batch_chosen_arrives_under_the_batches_that_left(): void
    {
        $product = $this->product();
        $this->batchIn($product, 'LATER', 5, '2027-06-01');
        $this->batchIn($product, 'SOONER', 2, '2026-09-01');
        $this->post(route('inventory.stock-transfers.store'), ['from_warehouse_id' => $this->from->id, 'to_warehouse_id' => $this->to->id, 'transfer_date' => '2026-06-15', 'items' => [['product_id' => $product->id, 'quantity' => 4]]])->assertSessionHasNoErrors();
        $transfer = StockTransfer::firstOrFail();

        $this->post(route('inventory.stock-transfers.send', $transfer->id))->assertSessionHasNoErrors();
        $this->post(route('inventory.stock-transfers.receive', $transfer->id))->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(2, (float) $this->batch($product, $this->to, 'SOONER')->quantity, 0.001);
        $this->assertEqualsWithDelta(2, (float) $this->batch($product, $this->to, 'LATER')->quantity, 0.001);
        $this->assertSame('received', $transfer->fresh()->status);
    }

    public function test_a_partial_receipt_takes_the_batches_in_the_order_they_left(): void
    {
        $product = $this->product();
        $this->batchIn($product, 'LATER', 5, '2027-06-01');
        $this->batchIn($product, 'SOONER', 2, '2026-09-01');
        $this->post(route('inventory.stock-transfers.store'), ['from_warehouse_id' => $this->from->id, 'to_warehouse_id' => $this->to->id, 'transfer_date' => '2026-06-15', 'items' => [['product_id' => $product->id, 'quantity' => 4]]])->assertSessionHasNoErrors();
        $transfer = StockTransfer::firstOrFail();
        $this->post(route('inventory.stock-transfers.send', $transfer->id))->assertSessionHasNoErrors();

        $this->post(route('inventory.stock-transfers.receive', $transfer->id), ['received' => [$transfer->items()->firstOrFail()->id => 3]])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(2, (float) $this->batch($product, $this->to, 'SOONER')->quantity, 0.001);
        $this->assertEqualsWithDelta(1, (float) $this->batch($product, $this->to, 'LATER')->quantity, 0.001);
        $this->assertEqualsWithDelta(3, $this->stock->onHand($product->id, $this->to->id), 0.001);
    }

    protected function approveCount(Product $product, float $counted, ?Warehouse $warehouse = null)
    {
        $warehouse ??= $this->from;
        $this->post(route('inventory.counts.store'), ['warehouse_id' => $warehouse->id, 'count_date' => '2026-06-15', 'count_type' => 'partial'])->assertSessionHasNoErrors();
        $count = StockCount::latest('id')->firstOrFail();
        $this->put(route('inventory.counts.update', $count), ['lines' => [['product_id' => $product->id, 'counted_quantity' => $counted]]])->assertSessionHasNoErrors();

        return $this->post(route('inventory.counts.approve', $count));
    }

    public function test_a_counted_surplus_joins_the_batch_that_leaves_first(): void
    {
        $product = $this->product();
        $this->batchIn($product, 'LATER', 5, '2027-06-01');
        $this->batchIn($product, 'SOONER', 2, '2026-09-01');

        $this->approveCount($product, 8)->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(8, $this->stock->onHand($product->id, $this->from->id), 0.001);
        $this->assertEqualsWithDelta(3, (float) $this->batch($product, $this->from, 'SOONER')->quantity, 0.001);
        $this->assertEqualsWithDelta(5, (float) $this->batch($product, $this->from, 'LATER')->quantity, 0.001);
    }

    public function test_a_surplus_skips_a_batch_that_is_used_up(): void
    {
        $product = $this->product();
        $this->batchIn($product, 'EMPTY', 1, '2026-07-01');
        $this->stock->issue(['product_id' => $product->id, 'warehouse_id' => $this->from->id, 'quantity' => 1, 'type' => 'adjustment', 'date' => now()]);
        $this->batchIn($product, 'FULL', 2, '2027-01-01');

        $this->approveCount($product, 3)->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(3, (float) $this->batch($product, $this->from, 'FULL')->quantity, 0.001);
        $this->assertEqualsWithDelta(0, (float) $this->batch($product, $this->from, 'EMPTY')->quantity, 0.001);
    }

    public function test_a_surplus_where_the_warehouse_has_no_batch_gets_one_named_after_the_count(): void
    {
        $product = $this->product();

        $this->approveCount($product, 2, $this->to)->assertSessionHasNoErrors();

        $count = StockCount::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(2, (float) $this->batch($product, $this->to, 'COUNT-'.$count->count_number)->quantity, 0.001);
    }

    public function test_a_serial_surplus_is_still_refused_because_the_numbers_are_unknown(): void
    {
        $product = $this->product('serial');
        $this->stock->receive(['product_id' => $product->id, 'warehouse_id' => $this->from->id, 'quantity' => 1, 'unit_cost' => 10, 'type' => 'opening', 'date' => now(), 'serials' => ['S-1']]);

        $this->approveCount($product, 2)->assertSessionHasErrors('items');

        $this->assertEqualsWithDelta(1, $this->stock->onHand($product->id, $this->from->id), 0.001);
        $this->assertSame(1, ProductSerial::where('product_id', $product->id)->where('status', 'in_stock')->count());
    }
}

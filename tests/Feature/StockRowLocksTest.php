<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Inventory\StockService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ObservesRowLocks;
use Tests\TestCase;

/**
 * A document that read anything earlier has an older snapshot: a plain read misses the stock row another document just
 * committed, so the row is added with INSERT IGNORE and only then read under lock (never a locking read of a missing row).
 */
class StockRowLocksTest extends TestCase
{
    use ObservesRowLocks, RefreshDatabase;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function product(array $attributes = []): Product
    {
        return Product::create($attributes + ['name_ar' => 'صنف', 'sku' => 'P-'.uniqid(), 'selling_price' => 1, 'track_inventory' => true, 'is_active' => true]);
    }

    protected function receipt(Product $product, float $quantity = 1, array $more = []): array
    {
        return $more + ['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => $quantity, 'unit_cost' => 10, 'type' => 'purchase', 'date' => now()];
    }

    /** Each query on the table, as it ran: «insert» (with whether it ignores duplicates), «locked» or «plain». */
    protected function accessTo(string $table, array $queries): array
    {
        return array_values(array_map(fn ($q) => match (true) {
            (bool) preg_match('/^\s*insert\s+(or\s+)?ignore/i', $q['sql']) => 'insert-ignore',
            (bool) preg_match('/^\s*insert/i', $q['sql']) => 'insert',
            $q['locked'] => 'locked',
            default => 'plain',
        }, array_filter($queries, fn ($q) => $q['table'] === $table && ! preg_match('/^\s*update/i', $q['sql']))));
    }

    public function test_the_first_receipt_adds_the_stock_row_with_insert_ignore_and_only_then_locks_it(): void
    {
        $product = $this->product();

        $queries = $this->queriesOf(fn () => app(StockService::class)->receive($this->receipt($product)));

        $this->assertSame(['plain', 'insert-ignore', 'locked'], array_slice($this->accessTo('inventory_stocks', $queries), 0, 3));
        $this->assertEqualsWithDelta(1, app(StockService::class)->onHand($product->id, $this->warehouse->id), 0.001);
    }

    public function test_a_row_that_exists_is_reused_not_inserted_again(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->receive($this->receipt($product));

        $stock->receive($this->receipt($product, 2));

        $this->assertSame(1, InventoryStock::where('product_id', $product->id)->count());
        $this->assertEqualsWithDelta(3, $stock->onHand($product->id, $this->warehouse->id), 0.001);
    }

    public function test_a_new_batch_is_added_the_same_way(): void
    {
        $product = $this->product(['tracking' => 'batch']);

        $queries = $this->queriesOf(fn () => app(StockService::class)->receive($this->receipt($product, 1, ['batch_number' => 'B1', 'expiry_date' => '2027-01-31'])));

        $this->assertSame(['plain', 'insert-ignore', 'locked'], array_slice($this->accessTo('product_batches', $queries), 0, 3));
        $this->assertSame('2027-01-31', ProductBatch::where('product_id', $product->id)->firstOrFail()->expiry_date->toDateString());
    }

    public function test_a_row_that_exists_is_only_read_and_locked(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->receive($this->receipt($product));

        $queries = $this->queriesOf(fn () => $stock->receive($this->receipt($product)));

        $this->assertSame(['plain', 'locked'], array_slice($this->accessTo('inventory_stocks', $queries), 0, 2));
    }

    public function test_a_count_locks_the_product_before_its_stock_like_every_other_movement(): void
    {
        $product = $this->product();
        app(StockService::class)->receive($this->receipt($product, 5));

        $locked = $this->tablesLockedBy(fn () => app(StockService::class)->adjustTo($product->id, $this->warehouse->id, 3, ['type' => 'count', 'date' => now()]));

        $this->assertSame(['products', 'inventory_stocks'], array_slice($locked, 0, 2), 'The opposite order is a deadlock with a receipt of the same product.');
    }
}

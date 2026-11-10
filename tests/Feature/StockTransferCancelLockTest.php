<?php

namespace Tests\Feature;

use App\Http\Controllers\StockTransferController;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Inventory\StockService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** A transfer that has just been sent has goods on the road: a cancel from a stale screen must not mark it cancelled. */
class StockTransferCancelLockTest extends TestCase
{
    use RefreshDatabase;

    protected StockTransfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $from = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $to = Warehouse::create(['name_ar' => 'فرع', 'code' => 'W2', 'is_active' => true]);
        $product = Product::create(['name_ar' => 'صنف', 'sku' => 'P1', 'selling_price' => 1, 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $product->id, 'warehouse_id' => $from->id, 'quantity' => 10, 'unit_cost' => 5, 'type' => 'opening', 'date' => now()]);
        $this->post(route('inventory.stock-transfers.store'), ['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'transfer_date' => today()->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 4]]])->assertSessionHasNoErrors();
        $this->transfer = StockTransfer::firstOrFail();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_transfer_sent_meanwhile_is_not_cancelled(): void
    {
        $stale = StockTransfer::findOrFail($this->transfer->id);
        $this->post(route('inventory.stock-transfers.send', $this->transfer->id))->assertSessionHasNoErrors();

        try {
            app(StockTransferController::class)->cancel($stale);
            $this->fail('A transfer on the road was cancelled.');
        } catch (ValidationException) {
            $this->assertSame('in_transit', $this->transfer->fresh()->status);
        }
    }

    public function test_a_draft_is_still_cancelled(): void
    {
        $this->post(route('inventory.stock-transfers.cancel', $this->transfer->id))->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $this->transfer->fresh()->status);
    }
}

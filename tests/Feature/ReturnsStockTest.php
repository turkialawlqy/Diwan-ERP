<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Inventory\StockService;
use App\Services\Sales\InvoiceChanges;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesCycleService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Returns and undone invoices put back exactly what left: base units, kit components, the very batch and serials; services nothing. */
class ReturnsStockTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $wh;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $company = Company::factory()->create(['currency' => 'YER', 'country_code' => 'YE', 'tax_number' => '1234567']); // no e-invoicing: invoices can be edited / deleted
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        Branch::create(['name_ar' => 'صنعاء', 'code' => 'SAN', 'is_main' => true, 'is_active' => true, 'country_code' => 'YE']);
        $this->wh = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function product(array $a = []): Product
    {
        return Product::create($a + ['name_ar' => 'صنف', 'sku' => 'P-'.uniqid(), 'selling_price' => 100, 'purchase_price' => 0, 'track_inventory' => true, 'is_active' => true]);
    }

    protected function stock(): StockService
    {
        return app(StockService::class);
    }

    protected function sell(array $lines)
    {
        return app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'warehouse_id' => $this->wh->id, 'payment_method' => 'cash', 'payment_status' => 'paid'], $lines);
    }

    protected function giveBack($invoice, array $lines)
    {
        $lines = collect($lines)->map(fn ($l) => ['invoice_item_id' => $invoice->items->firstWhere('product_id', $l[0])->id, 'quantity' => $l[1]] + ($l[2] ?? []))->all();

        return app(SalesCycleService::class)->returnInvoice($invoice->fresh('items'), ['credit_note_date' => '2026-06-15', 'reason' => 'مرتجع', 'refund_method' => 'credit', 'warehouse_id' => $this->wh->id], $lines);
    }

    public function test_a_returned_carton_restocks_its_base_units(): void
    {
        $p = $this->product();
        $carton = Unit::create(['name_ar' => 'كرتونة', 'code' => 'CTN']);
        ProductUnit::create(['product_id' => $p->id, 'unit_id' => $carton->id, 'factor' => 12, 'is_default' => false]);
        $this->stock()->receive(['product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'quantity' => 100, 'unit_cost' => 5, 'type' => 'opening']);

        $inv = $this->sell([['product_id' => $p->id, 'item_name' => 'صنف', 'quantity' => 2, 'unit_price' => 60, 'unit_id' => $carton->id]]);
        $this->assertSame(76.0, $this->stock()->onHand($p->id)); // 2 cartons = 24 pieces
        $this->giveBack($inv, [[$p->id, 1]]);
        $this->assertSame(88.0, $this->stock()->onHand($p->id)); // one carton back = 12 pieces, not 1
    }

    public function test_a_service_line_returns_no_stock_and_a_kit_returns_its_components(): void
    {
        $service = $this->product(['track_inventory' => false, 'type' => 'service']);
        $a = $this->product();
        $b = $this->product();
        $kit = $this->product(['is_kit' => true]);
        $kit->kitItems()->create(['component_id' => $a->id, 'quantity' => 2]);
        $kit->kitItems()->create(['component_id' => $b->id, 'quantity' => 1]);
        foreach ([$a, $b] as $c) {
            $this->stock()->receive(['product_id' => $c->id, 'warehouse_id' => $this->wh->id, 'quantity' => 10, 'unit_cost' => 5, 'type' => 'opening']);
        }

        $inv = $this->sell([['product_id' => $service->id, 'item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 50], ['product_id' => $kit->id, 'item_name' => 'طقم', 'quantity' => 2, 'unit_price' => 100]]);
        $this->assertSame([6.0, 8.0], [$this->stock()->onHand($a->id), $this->stock()->onHand($b->id)]);

        $this->giveBack($inv, [[$service->id, 1], [$kit->id, 2]]);
        $this->assertSame(0.0, $this->stock()->onHand($service->id));   // no phantom stock for a service
        $this->assertSame(0.0, $this->stock()->onHand($kit->id));       // the kit itself is never stocked
        $this->assertSame([10.0, 10.0], [$this->stock()->onHand($a->id), $this->stock()->onHand($b->id)]);
    }

    public function test_deleting_an_invoice_of_batch_and_serial_items_puts_them_back(): void
    {
        $batch = $this->product(['tracking' => 'batch']);
        $serial = $this->product(['tracking' => 'serial']);
        $this->stock()->receive(['product_id' => $batch->id, 'warehouse_id' => $this->wh->id, 'quantity' => 10, 'unit_cost' => 5, 'type' => 'opening', 'batch_number' => 'B1', 'expiry_date' => '2027-01-01']);
        $this->stock()->receive(['product_id' => $serial->id, 'warehouse_id' => $this->wh->id, 'quantity' => 2, 'unit_cost' => 50, 'type' => 'opening', 'serials' => ['S1', 'S2']]);

        $inv = $this->sell([
            ['product_id' => $batch->id, 'item_name' => 'دفعة', 'quantity' => 4, 'unit_price' => 10],
            ['product_id' => $serial->id, 'item_name' => 'تسلسلي', 'quantity' => 2, 'unit_price' => 80, 'serials' => ['S1', 'S2']],
        ]);
        $this->assertSame(6.0, (float) ProductBatch::where('product_id', $batch->id)->value('quantity'));
        $this->assertSame(0, ProductSerial::where('product_id', $serial->id)->where('status', 'in_stock')->count());

        app(InvoiceChanges::class)->delete($inv);
        $this->assertSame(10.0, (float) ProductBatch::where('product_id', $batch->id)->value('quantity')); // the same batch, not a new one
        $this->assertSame(1, ProductBatch::where('product_id', $batch->id)->count());
        $this->assertSame(2, ProductSerial::where('product_id', $serial->id)->where('status', 'in_stock')->count());
    }
}

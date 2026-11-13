<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\DocumentCancellation;
use App\Services\Inventory\StockService;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\Sales\SalesCycleService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Cancelling the delivery or the receipt that completed an order opens the order again. */
class CancelledDocumentsReopenOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->product = Product::create(['name_ar' => 'صنف', 'sku' => 'P1', 'selling_price' => 100, 'purchase_price' => 20, 'tax_rate' => 15, 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 20, 'unit_cost' => 20, 'type' => 'opening', 'date' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function salesOrder(float $quantity)
    {
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);

        return app(SalesCycleService::class)->createOrder(['customer_id' => $customer->id, 'order_date' => today()->toDateString(), 'warehouse_id' => $this->warehouse->id],
            [['product_id' => $this->product->id, 'item_name' => 'صنف', 'quantity' => $quantity, 'unit_price' => 100, 'tax_rate' => 15]]);
    }

    protected function deliver($order, float $quantity)
    {
        return app(SalesCycleService::class)->deliver($order->fresh(), ['delivery_date' => today()->toDateString()], [['sales_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => $quantity]]);
    }

    protected function purchaseOrder(float $quantity): PurchaseOrder
    {
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $service = app(PurchaseCycleService::class);
        $order = $service->createOrder(['supplier_id' => $supplier->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => today()->toDateString()],
            [['product_id' => $this->product->id, 'item_name' => 'صنف', 'quantity' => $quantity, 'unit_price' => 10, 'tax_rate' => 15]]);
        $service->submitOrder($order);

        return $order->fresh();
    }

    protected function receive(PurchaseOrder $order, float $quantity)
    {
        return app(PurchaseCycleService::class)->receive($order->fresh(), ['receipt_date' => today()->toDateString(), 'warehouse_id' => $this->warehouse->id], [['purchase_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => $quantity]]);
    }

    public function test_cancelling_the_only_delivery_of_a_fully_delivered_order_opens_it_again(): void
    {
        $order = $this->salesOrder(5);
        $note = $this->deliver($order, 5);
        $this->assertSame('delivered', $order->fresh()->status);

        app(DocumentCancellation::class)->deliveryNote($note, 'خطأ');

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->deliver($order, 5); // it can be delivered again
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_cancelling_one_of_two_deliveries_leaves_the_order_partly_delivered(): void
    {
        $order = $this->salesOrder(5);
        $first = $this->deliver($order, 2);
        $second = $this->deliver($order, 3);

        app(DocumentCancellation::class)->deliveryNote($second, 'خطأ');
        $this->assertSame('partially_delivered', $order->fresh()->status);

        app(DocumentCancellation::class)->deliveryNote($first, 'خطأ');
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_cancelling_the_only_receipt_of_a_fully_received_order_opens_it_again(): void
    {
        $order = $this->purchaseOrder(5);
        $receipt = $this->receive($order, 5);
        $this->assertSame('received', $order->fresh()->status);

        app(DocumentCancellation::class)->goodsReceipt($receipt, 'خطأ');

        $this->assertSame('approved', $order->fresh()->status);
        $this->receive($order, 5);
        $this->assertSame('received', $order->fresh()->status);
    }

    public function test_cancelling_one_of_two_receipts_leaves_the_order_partly_received(): void
    {
        $order = $this->purchaseOrder(5);
        $first = $this->receive($order, 2);
        $second = $this->receive($order, 3);

        app(DocumentCancellation::class)->goodsReceipt($second, 'خطأ');
        $this->assertSame('partially_received', $order->fresh()->status);

        app(DocumentCancellation::class)->goodsReceipt($first, 'خطأ');
        $this->assertSame('approved', $order->fresh()->status);
    }
}

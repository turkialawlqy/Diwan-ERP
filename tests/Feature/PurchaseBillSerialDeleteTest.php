<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Inventory\StockService;
use App\Services\Purchases\PurchaseInvoiceChanges;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Deleting a bill of serial-tracked goods takes the serials it brought in out of the warehouse. */
class PurchaseBillSerialDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected Supplier $supplier;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $company = Company::factory()->create(['currency' => 'YER', 'country_code' => 'YE', 'tax_number' => '1234567']); // no e-invoicing: bills can be deleted
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $this->branch = Branch::create(['name_ar' => 'صنعاء', 'code' => 'SAN', 'is_main' => true, 'is_active' => true, 'country_code' => 'YE']);
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function product(string $tracking = 'serial'): Product
    {
        return Product::create(['name_ar' => 'صنف', 'sku' => 'P-'.uniqid(), 'selling_price' => 100, 'purchase_price' => 0, 'track_inventory' => true, 'tracking' => $tracking, 'is_active' => true]);
    }

    protected function bill(Product $product, array $serials): PurchaseInvoice
    {
        $this->post(route('purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'branch_id' => $this->branch->id, 'invoice_date' => today()->toDateString(), 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'items' => [['product_id' => $product->id, 'item_name' => 'صنف', 'quantity' => count($serials), 'unit_price' => 40, 'tax_rate' => 0, 'serials' => $serials]],
        ])->assertSessionHasNoErrors();

        return PurchaseInvoice::latest('id')->firstOrFail();
    }

    public function test_deleting_the_bill_takes_its_serials_out_of_stock(): void
    {
        $product = $this->product();
        $bill = $this->bill($product, ['S-1', 'S-2']);

        app(PurchaseInvoiceChanges::class)->delete($bill);

        $this->assertEqualsWithDelta(0, app(StockService::class)->onHand($product->id, $this->warehouse->id), 0.001);
        $this->assertSame(0, ProductSerial::where('product_id', $product->id)->where('status', 'in_stock')->count());
    }

    public function test_a_bill_whose_serial_was_sold_is_not_deleted(): void
    {
        $product = $this->product();
        $bill = $this->bill($product, ['S-1', 'S-2']);
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
        app(InvoiceService::class)->create(['customer_id' => $customer->id, 'branch_id' => $this->branch->id, 'warehouse_id' => $this->warehouse->id, 'invoice_date' => today()->toDateString(), 'payment_method' => 'credit', 'payment_status' => 'unpaid'],
            [['product_id' => $product->id, 'item_name' => 'صنف', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0, 'serials' => ['S-1']]]);

        try {
            app(PurchaseInvoiceChanges::class)->delete($bill);
            $this->fail('A bill whose goods were sold was deleted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('مرتجع مشتريات', collect($e->errors())->flatten()->first());
        }
        $this->assertDatabaseHas('purchase_invoices', ['id' => $bill->id]);
    }

    public function test_other_goods_are_deleted_as_before(): void
    {
        $product = $this->product('none');
        $this->post(route('purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'branch_id' => $this->branch->id, 'invoice_date' => today()->toDateString(), 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'items' => [['product_id' => $product->id, 'item_name' => 'صنف', 'quantity' => 3, 'unit_price' => 40, 'tax_rate' => 0]],
        ])->assertSessionHasNoErrors();

        app(PurchaseInvoiceChanges::class)->delete(PurchaseInvoice::latest('id')->firstOrFail());

        $this->assertEqualsWithDelta(0, app(StockService::class)->onHand($product->id, $this->warehouse->id), 0.001);
    }
}

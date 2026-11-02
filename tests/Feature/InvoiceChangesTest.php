<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Inventory\StockService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Invoices of branches without government e-invoicing can be edited and deleted. */
class InvoiceChangesTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $sanaa;

    protected Branch $riyadh;

    protected Customer $customer;

    protected Product $product;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $company = Company::factory()->create(['currency' => 'YER', 'country_code' => 'YE', 'tax_number' => '1234567']);
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $this->sanaa = Branch::create(['name_ar' => 'صنعاء', 'code' => 'SAN', 'is_main' => true, 'is_active' => true, 'country_code' => 'YE']);
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_active' => true, 'country_code' => 'SA']);
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
        $this->product = Product::create(['name_ar' => 'صنف', 'sku' => 'P1', 'selling_price' => 100, 'purchase_price' => 40, 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 20, 'unit_cost' => 40, 'type' => 'opening']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function payload(Branch $branch, float $qty): array
    {
        return [
            'customer_id' => $this->customer->id, 'branch_id' => $branch->id, 'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(), 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'items' => [['product_id' => $this->product->id, 'item_name' => 'صنف', 'quantity' => $qty, 'unit_price' => 100, 'tax_rate' => 0]],
        ];
    }

    protected function onHand(): float
    {
        return app(StockService::class)->onHand($this->product->id, $this->warehouse->id);
    }

    protected function receivables(): float
    {
        return round((float) Account::find(app(AccountMap::class)->id('receivables'))->balance, 2);
    }

    public function test_an_invoice_outside_e_invoicing_is_edited_on_the_same_record_and_number(): void
    {
        $this->post(route('sales.invoices.store'), $this->payload($this->sanaa, 5))->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->assertSame(15.0, $this->onHand());

        $this->get(route('sales.invoices.edit', $invoice->id))->assertOk()->assertInertia(fn ($p) => $p->component('Sales/Invoices/Create')->where('invoice.id', $invoice->id));
        $this->put(route('sales.invoices.update', $invoice->id), $this->payload($this->sanaa, 3))->assertSessionHasNoErrors();

        $fresh = $invoice->fresh();
        $this->assertSame($invoice->invoice_number, $fresh->invoice_number);
        $this->assertEquals(300, $fresh->total);
        $this->assertSame(1, $fresh->items()->count());
        $this->assertSame(17.0, $this->onHand());
        $this->assertSame(300.0, $this->receivables());
        $this->assertEquals(300, $this->customer->fresh()->current_balance);
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);
    }

    public function test_the_stock_return_of_an_edit_carries_the_invoice_date(): void
    {
        $date = now()->subDays(5)->toDateString();
        $this->post(route('sales.invoices.store'), ['invoice_date' => $date] + $this->payload($this->sanaa, 5))->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();

        $this->put(route('sales.invoices.update', $invoice->id), ['invoice_date' => $date] + $this->payload($this->sanaa, 3))->assertSessionHasNoErrors();

        $return = StockMovement::where('type', 'sale_cancel')->firstOrFail();
        $this->assertSame($date, Carbon::parse($return->date)->toDateString());
        $this->assertSame(17.0, $this->onHand());
    }

    public function test_deleting_reverses_the_entry_and_returns_the_stock(): void
    {
        $this->post(route('sales.invoices.store'), $this->payload($this->sanaa, 5));
        $invoice = Invoice::firstOrFail();

        $this->delete(route('sales.invoices.destroy', $invoice->id))->assertRedirect(route('sales.invoices.index'));

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertSame(20.0, $this->onHand());
        $this->assertSame(0.0, $this->receivables());
        $this->assertEquals(0, $this->customer->fresh()->current_balance);
    }

    public function test_saudi_invoices_and_invoices_with_payments_stay_locked(): void
    {
        Currency::create(['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol_ar' => 'ر.س', 'symbol_en' => 'SAR', 'exchange_rate' => 1]);
        $this->post(route('context.branch'), ['branch_id' => $this->riyadh->id]);
        $this->post(route('sales.invoices.store'), ['invoice_type' => 'simplified'] + $this->payload($this->riyadh, 1) + ['payment_status' => 'paid', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $saudi = Invoice::where('branch_id', $this->riyadh->id)->firstOrFail();
        $this->delete(route('sales.invoices.destroy', $saudi->id))->assertSessionHas('error');
        $this->assertDatabaseHas('invoices', ['id' => $saudi->id]);
        $this->get(route('sales.invoices.show', $saudi->id))->assertInertia(fn ($p) => $p->whereNot('changeBlocker', null));

        $this->post(route('context.branch'), ['branch_id' => $this->sanaa->id]);
        $this->post(route('sales.invoices.store'), $this->payload($this->sanaa, 1));
        $local = Invoice::where('branch_id', $this->sanaa->id)->firstOrFail();
        \DB::table('customer_payments')->insert(['company_id' => $local->company_id, 'customer_id' => $this->customer->id, 'invoice_id' => $local->id, 'payment_number' => 'R1', 'amount' => 10, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now()]);
        $this->delete(route('sales.invoices.destroy', $local->id))->assertSessionHas('error');
        $this->assertDatabaseHas('invoices', ['id' => $local->id]);
    }

    public function test_a_purchase_bill_is_edited_and_deleted_outside_e_invoicing(): void
    {
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $bill = fn (float $qty) => [
            'supplier_id' => $supplier->id, 'warehouse_id' => $this->warehouse->id, 'branch_id' => $this->sanaa->id, 'invoice_date' => now()->toDateString(),
            'payments' => [['method' => 'cash', 'amount' => 50]],
            'items' => [['product_id' => $this->product->id, 'item_name' => 'صنف', 'quantity' => $qty, 'unit_price' => 40, 'tax_rate' => 0]],
        ];
        $this->post(route('purchases.invoices.store'), $bill(10))->assertSessionHasNoErrors();
        $invoice = PurchaseInvoice::firstOrFail();
        $this->assertSame(30.0, $this->onHand());

        $this->get(route('purchases.invoices.edit', $invoice->id))->assertOk()->assertInertia(fn ($p) => $p->where('invoice.id', $invoice->id));
        $this->put(route('purchases.invoices.update', $invoice->id), $bill(5))->assertSessionHasNoErrors();
        $this->assertSame($invoice->invoice_number, $invoice->fresh()->invoice_number);
        $this->assertEquals(200, $invoice->fresh()->total);
        $this->assertSame(25.0, $this->onHand());
        $this->assertSame(1, SupplierPayment::count());
        $this->assertEquals(150, $supplier->fresh()->current_balance);

        $this->delete(route('purchases.invoices.destroy', $invoice->id))->assertRedirect(route('purchases.invoices.index'));
        $this->assertSame(20.0, $this->onHand());
        $this->assertSame(0, SupplierPayment::count());
        $this->assertEquals(0, $supplier->fresh()->current_balance);
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);
    }
}

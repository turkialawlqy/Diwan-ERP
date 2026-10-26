<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Inventory\StockService;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\PriceResolver;
use App\Services\Sales\SalesCycleService;
use App\Services\TenantManager;
use App\Services\Workflow\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesPurchaseCycleTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Customer $customer;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');

        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        $owner = $this->userWithRole($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($owner);

        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function product(float $stock = 0, float $cost = 10): Product
    {
        $product = Product::create(['name_ar' => 'صنف', 'sku' => 'P-'.uniqid(), 'selling_price' => 100, 'purchase_price' => $cost, 'track_inventory' => true, 'is_active' => true]);
        if ($stock > 0) {
            app(StockService::class)->receive(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => $stock, 'unit_cost' => $cost, 'type' => 'opening']);
        }

        return $product;
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find(app(AccountMap::class)->id($role))->balance, 2);
    }

    protected function assertLedgerBalanced(): void
    {
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);
    }

    protected function sales(): SalesCycleService
    {
        return app(SalesCycleService::class);
    }

    protected function purchases(): PurchaseCycleService
    {
        return app(PurchaseCycleService::class);
    }

    protected function line(Product $p, float $qty, float $price = 100): array
    {
        return ['product_id' => $p->id, 'item_name' => $p->name_ar, 'quantity' => $qty, 'unit_price' => $price, 'tax_rate' => 15];
    }

    // ----- Sales -----

    public function test_order_partial_delivery_then_invoice_posts_cost_once(): void
    {
        $p = $this->product(10, 40);
        $order = $this->sales()->createOrder(['customer_id' => $this->customer->id, 'order_date' => '2026-06-15'], [$this->line($p, 6)]);
        $this->assertSame('confirmed', $order->status);

        $item = $order->items()->first();
        $note = $this->sales()->deliver($order, ['delivery_date' => '2026-06-15'], [['sales_order_item_id' => $item->id, 'quantity' => 4]]);

        $this->assertSame(6.0, app(StockService::class)->onHand($p->id));
        $this->assertSame(160.0, $this->balance('cogs'));
        $this->assertSame('partially_delivered', $order->fresh()->status);

        $invoice = $this->sales()->invoiceDelivery($note);
        $this->assertSame(460.0, (float) $invoice->total); // 4 x 100 + 15%
        $this->assertSame(160.0, $this->balance('cogs'), 'Cost is not posted twice');
        $this->assertSame(6.0, app(StockService::class)->onHand($p->id), 'Stock is not issued twice');
        $this->assertSame(460.0, $this->balance('receivables'));
        $this->assertSame('invoiced', $note->fresh()->status);

        // Over-delivery is refused.
        $this->expectException(ValidationException::class);
        $this->sales()->deliver($order, ['delivery_date' => '2026-06-15'], [['sales_order_item_id' => $item->id, 'quantity' => 3]]);
    }

    public function test_invoice_order_directly_issues_remaining_stock(): void
    {
        $p = $this->product(10, 40);
        $order = $this->sales()->createOrder(['customer_id' => $this->customer->id, 'order_date' => '2026-06-15'], [$this->line($p, 5)]);
        $invoice = $this->sales()->invoiceOrder($order);

        $this->assertSame(575.0, (float) $invoice->total);
        $this->assertSame(5.0, app(StockService::class)->onHand($p->id));
        $this->assertSame(200.0, $this->balance('cogs'));
        $this->assertSame('invoiced', $order->fresh()->status);
        $this->assertLedgerBalanced();
    }

    public function test_item_return_is_limited_to_returnable_quantity_and_restocks_at_cost(): void
    {
        $p = $this->product(10, 40);
        $invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15'], [$this->line($p, 4)]);
        $item = $invoice->items()->first();

        $note = $this->sales()->returnInvoice($invoice, ['credit_note_date' => '2026-06-16', 'reason' => 'تالف'], [['invoice_item_id' => $item->id, 'quantity' => 3]]);

        $this->assertSame(345.0, (float) $note->total);
        $this->assertSame(9.0, app(StockService::class)->onHand($p->id));
        $this->assertSame(40.0, $this->balance('cogs')); // 160 issued - 120 returned
        $this->assertSame(115.0, (float) $invoice->fresh()->remaining_amount);
        $this->assertSame(115.0, (float) $this->customer->fresh()->current_balance);
        $this->assertLedgerBalanced();

        $this->expectException(ValidationException::class);
        $this->sales()->returnInvoice($invoice, ['credit_note_date' => '2026-06-16', 'reason' => 'x'], [['invoice_item_id' => $item->id, 'quantity' => 2]]);
    }

    public function test_debit_note_and_auto_allocated_receipt(): void
    {
        $p = $this->product(10, 40);
        $first = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10'], [$this->line($p, 1)]);
        $second = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-12'], [$this->line($p, 1)]);

        $debit = $this->sales()->debitNote(['customer_id' => $this->customer->id, 'invoice_id' => $first->id, 'debit_note_date' => '2026-06-15', 'reason' => 'فرق سعر', 'subtotal' => 100]);
        $this->assertSame(115.0, (float) $debit->total);
        $this->assertSame(345.0, (float) $this->customer->fresh()->current_balance);

        $payment = CustomerPayment::create(['customer_id' => $this->customer->id, 'payment_number' => 'R-1', 'payment_date' => '2026-06-15', 'amount' => 150, 'payment_method' => 'cash']);
        $this->sales()->allocatePayment($payment);

        $this->assertSame('paid', $first->fresh()->payment_status);
        $this->assertSame(80.0, (float) $second->fresh()->remaining_amount);
        $this->assertSame('partially_paid', $second->fresh()->payment_status);
        $this->assertLedgerBalanced();
    }

    public function test_price_list_with_quantity_breaks(): void
    {
        $p = $this->product();
        $list = PriceList::create(['name' => 'جملة', 'is_active' => true]);
        $list->items()->create(['product_id' => $p->id, 'min_quantity' => 1, 'price' => 90]);
        $list->items()->create(['product_id' => $p->id, 'min_quantity' => 10, 'price' => 80]);
        $this->customer->update(['price_list_id' => $list->id]);

        $resolver = app(PriceResolver::class);
        $this->assertSame(90.0, $resolver->price($p, $this->customer->fresh(), 5)['price']);
        $this->assertSame(80.0, $resolver->price($p, $this->customer->fresh(), 12)['price']);
        $this->assertSame(100.0, $resolver->price($p, null, 12)['price']);
    }

    public function test_approval_rule_holds_order_and_blocks_self_approval(): void
    {
        ApprovalRule::create(['document_type' => 'sales_order', 'min_amount' => 1000, 'is_active' => true]);
        $p = $this->product(100, 10);

        $clerk = $this->userWithRole($this->company, 'sales');
        $this->actingAs($clerk);
        $order = $this->sales()->createOrder(['customer_id' => $this->customer->id, 'order_date' => '2026-06-15'], [$this->line($p, 20)]);
        $this->assertSame('pending_approval', $order->status);

        try {
            app(ApprovalService::class)->approve($order);
            $this->fail('Creator approved own order');
        } catch (ValidationException) {
        }

        try {
            $this->sales()->deliver($order, ['delivery_date' => '2026-06-15'], [['sales_order_item_id' => $order->items()->first()->id, 'quantity' => 1]]);
            $this->fail('Delivered an unapproved order');
        } catch (ValidationException) {
        }

        $manager = $this->userWithRole($this->company, 'accountant');
        $this->actingAs($manager);
        app(ApprovalService::class)->approve($order, 'confirmed');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertCount(2, app(ApprovalService::class)->history($order));
    }

    // ----- Purchases -----

    protected function purchaseOrder(Product $p, float $qty, float $price): PurchaseOrder
    {
        $order = $this->purchases()->createOrder(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => '2026-06-15', 'submit' => true],
            [$this->line($p, $qty, $price)],
        );
        $this->assertSame('approved', $order->status);

        return $order;
    }

    public function test_three_way_match_clears_grni_and_books_price_variance(): void
    {
        $p = $this->product();
        $order = $this->purchaseOrder($p, 10, 50);
        $poItem = $order->items->first();

        $receipt = $this->purchases()->receive($order, ['receipt_date' => '2026-06-15'], [['purchase_order_item_id' => $poItem->id, 'quantity' => 6]]);
        $this->assertSame(300.0, (float) $receipt->total_value);
        $this->assertSame(300.0, $this->balance('inventory'));
        $this->assertSame(300.0, $this->balance('grni'));
        $this->assertSame('partially_received', $order->fresh()->status);

        $grnItem = $receipt->items()->first();
        $invoice = $this->purchases()->invoiceReceipts([
            'supplier_id' => $this->supplier->id, 'supplier_invoice_number' => 'S-100', 'invoice_date' => '2026-06-16', 'accept_price_variance' => true,
        ], [['goods_receipt_item_id' => $grnItem->id, 'quantity' => 6, 'unit_price' => 52]]);

        $this->assertSame(358.8, (float) $invoice->total);
        $this->assertSame(0.0, $this->balance('grni'));
        // All 6 units are still held, so the price variance is capitalised into inventory.
        $this->assertSame(0.0, $this->balance('purchases_cogs'));
        $this->assertSame(312.0, $this->balance('inventory'));
        $this->assertSame(358.8, $this->balance('payables'));
        $this->assertSame('invoiced', $receipt->fresh()->status);
        $this->assertLedgerBalanced();

        // Duplicate supplier invoice number is refused.
        $this->expectException(ValidationException::class);
        $this->purchases()->assertUniqueSupplierInvoice($this->supplier->id, 'S-100');
    }

    public function test_price_above_order_and_over_receipt_are_refused(): void
    {
        $p = $this->product();
        $order = $this->purchaseOrder($p, 5, 50);
        $poItem = $order->items->first();

        try {
            $this->purchases()->receive($order, ['receipt_date' => '2026-06-15'], [['purchase_order_item_id' => $poItem->id, 'quantity' => 6]]);
            $this->fail('Over receipt accepted');
        } catch (ValidationException) {
        }

        $receipt = $this->purchases()->receive($order, ['receipt_date' => '2026-06-15'], [['purchase_order_item_id' => $poItem->id, 'quantity' => 5]]);
        $this->assertSame('received', $order->fresh()->status);

        $this->expectException(ValidationException::class);
        $this->purchases()->invoiceReceipts(['supplier_id' => $this->supplier->id, 'invoice_date' => '2026-06-16'],
            [['goods_receipt_item_id' => $receipt->items()->first()->id, 'quantity' => 5, 'unit_price' => 60]]);
    }

    public function test_purchase_return_reduces_stock_and_payable(): void
    {
        $p = $this->product();
        $order = $this->purchaseOrder($p, 10, 50);
        $receipt = $this->purchases()->receive($order, ['receipt_date' => '2026-06-15'], [['purchase_order_item_id' => $order->items->first()->id, 'quantity' => 10]]);
        $invoice = $this->purchases()->invoiceReceipts(['supplier_id' => $this->supplier->id, 'invoice_date' => '2026-06-16'],
            [['goods_receipt_item_id' => $receipt->items()->first()->id, 'quantity' => 10, 'unit_price' => 50]]);

        $return = $this->purchases()->returnInvoice($invoice, ['return_date' => '2026-06-17', 'settlement' => 'credit'],
            [['purchase_invoice_item_id' => $invoice->items()->first()->id, 'quantity' => 4]]);

        $this->assertSame(230.0, (float) $return->total);
        $this->assertSame(6.0, app(StockService::class)->onHand($p->id));
        $this->assertSame(300.0, $this->balance('inventory'));
        $this->assertSame(345.0, $this->balance('payables'));
        $this->assertSame(345.0, (float) $this->supplier->fresh()->current_balance);
        $this->assertLedgerBalanced();

        $this->expectException(ValidationException::class);
        $this->purchases()->returnInvoice($invoice, ['return_date' => '2026-06-17'], [['purchase_invoice_item_id' => $invoice->items()->first()->id, 'quantity' => 7]]);
    }

    public function test_landed_cost_splits_between_stock_on_hand_and_cogs(): void
    {
        $p = $this->product();
        $order = $this->purchaseOrder($p, 10, 50);
        $receipt = $this->purchases()->receive($order, ['receipt_date' => '2026-06-15'], [['purchase_order_item_id' => $order->items->first()->id, 'quantity' => 10]]);
        $invoice = $this->purchases()->invoiceReceipts(['supplier_id' => $this->supplier->id, 'invoice_date' => '2026-06-16'],
            [['goods_receipt_item_id' => $receipt->items()->first()->id, 'quantity' => 10, 'unit_price' => 50]]);

        // Sell 4 of 10 before the freight bill arrives.
        app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-16'], [$this->line($p, 4)]);

        $landed = $this->purchases()->landedCost($invoice, ['date' => '2026-06-17', 'description' => 'شحن', 'amount' => 100, 'tax_amount' => 15, 'paid_from' => 'payables', 'supplier_id' => $this->supplier->id]);

        $this->assertSame('LC-', substr($landed->landed_number, 0, 3));
        $this->assertSame(60.0, round($landed->allocation[0]['inventory'], 2));
        $this->assertSame(40.0, round($landed->allocation[0]['cogs'], 2));
        $this->assertSame(60.0, (float) $p->fresh()->average_cost); // (6 x 50 + 60) / 6
        $this->assertSame(360.0, $this->balance('inventory'));
        $this->assertLedgerBalanced();
    }

    public function test_purchase_request_to_order_with_approval(): void
    {
        ApprovalRule::create(['document_type' => 'purchase_request', 'min_amount' => 0, 'is_active' => true]);
        $p = $this->product();

        $clerk = $this->userWithRole($this->company, 'warehouse_keeper');
        $this->actingAs($clerk);
        $request = $this->purchases()->createRequest(['request_date' => '2026-06-15'], [['product_id' => $p->id, 'item_name' => 'صنف', 'quantity' => 3, 'estimated_price' => 20]]);
        $this->assertSame('pending_approval', $request->status);

        $this->actingAs($this->userWithRole($this->company, 'purchasing'));
        app(ApprovalService::class)->approve($request);

        $response = $this->post(route('purchases.requests.convert', $request->id), ['supplier_id' => $this->supplier->id]);
        $response->assertRedirect();
        $this->assertSame('ordered', $request->fresh()->status);
        $this->assertSame(1, PurchaseOrder::where('purchase_request_id', $request->id)->count());
    }

    public function test_receipt_endpoint_allocates_to_selected_invoices(): void
    {
        $p = $this->product(10, 40);
        $first = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10'], [$this->line($p, 1)]);
        $second = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-12'], [$this->line($p, 1)]);

        $this->post(route('sales.payments.store'), [
            'customer_id' => $this->customer->id, 'payment_date' => '2026-06-15', 'amount' => 115, 'payment_method' => 'cash',
            'allocations' => [['invoice_id' => $second->id, 'amount' => 115]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('unpaid', $first->fresh()->payment_status);
        $this->assertSame('paid', $second->fresh()->payment_status);
        $this->assertSame(115.0, (float) $this->customer->fresh()->current_balance);
    }

    public function test_cycle_pages_render(): void
    {
        $p = $this->product(20, 40);
        $order = $this->sales()->createOrder(['customer_id' => $this->customer->id, 'order_date' => '2026-06-15'], [$this->line($p, 2)]);
        $note = $this->sales()->deliver($order, ['delivery_date' => '2026-06-15'], [['sales_order_item_id' => $order->items()->first()->id, 'quantity' => 1]]);
        $po = $this->purchaseOrder($p, 3, 50);
        $receipt = $this->purchases()->receive($po, ['receipt_date' => '2026-06-15'], [['purchase_order_item_id' => $po->items->first()->id, 'quantity' => 3]]);
        $bill = $this->purchases()->invoiceReceipts(['supplier_id' => $this->supplier->id, 'invoice_date' => '2026-06-16'],
            [['goods_receipt_item_id' => $receipt->items()->first()->id, 'quantity' => 3, 'unit_price' => 50]]);
        $return = $this->purchases()->returnInvoice($bill, ['return_date' => '2026-06-17'], [['purchase_invoice_item_id' => $bill->items()->first()->id, 'quantity' => 1]]);
        $request = $this->purchases()->createRequest(['request_date' => '2026-06-15'], [['item_name' => 'خدمة', 'quantity' => 1]]);

        foreach ([
            route('sales.orders.index'), route('sales.orders.create'), route('sales.orders.show', $order),
            route('sales.deliveries.index'), route('sales.deliveries.show', $note),
            route('sales.debit-notes.index'), route('sales.price-lists.index'),
            route('purchases.orders.index'), route('purchases.orders.create'), route('purchases.orders.show', $po),
            route('purchases.receipts.index'), route('purchases.receipts.show', $receipt),
            route('purchases.invoices.from-receipts', ['supplier_id' => $this->supplier->id]), route('purchases.invoices.show', $bill),
            route('purchases.returns.index'), route('purchases.returns.create', ['invoice_id' => $bill->id]), route('purchases.returns.show', $return),
            route('purchases.requests.index'), route('purchases.requests.show', $request),
            route('purchases.landed-costs.index'), route('approval-rules.index'),
            route('sales.returns.create', ['invoice_id' => Invoice::first()?->id ?? $this->sales()->invoiceDelivery($note)->id]),
            route('lookup.price', ['product_id' => $p->id, 'customer_id' => $this->customer->id]),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_branch_data_scope_limits_documents(): void
    {
        $riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'B1', 'is_active' => true]);
        $jeddah = Branch::create(['name_ar' => 'جدة', 'code' => 'B2', 'is_active' => true]);
        $p = $this->product(10, 40);
        $mine = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'branch_id' => $riyadh->id], [$this->line($p, 1)]);
        $other = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'branch_id' => $jeddah->id], [$this->line($p, 1)]);

        $clerk = $this->userWithRole($this->company, 'sales');
        $clerk->forceFill(['branch_id' => $riyadh->id])->save();
        $this->actingAs($clerk);

        $this->assertSame([$mine->id], Invoice::pluck('id')->all());
        $this->get(route('sales.invoices.show', $other))->assertNotFound();

        // Granting the data scope permission lifts the restriction.
        $clerk->givePermissionTo('admin.data_scope.manage');
        $this->assertCount(2, Invoice::all());
    }
}

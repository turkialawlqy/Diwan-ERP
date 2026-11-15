<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseDraft;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\ErpAlert;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Inventory\StockService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * «إدخال مواد للمستودع»: the keeper adds items and quantities, the stock enters at once on a receipt with a provisional cost,
 * and a draft waits for accounting to add the supplier, the date and the prices.
 */
class StockInDraftTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Product $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->item = Product::create(['name_ar' => 'صنف مخزني', 'sku' => 'S1', 'track_inventory' => true, 'is_active' => true, 'purchase_price' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function balance(string $role): float
    {
        $id = app(AccountMap::class)->find($role);

        return round((float) DB::table('journal_entry_lines')->where('account_id', $id)->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as b')->value('b'), 2);
    }

    protected function keeper(): User
    {
        return $this->userWithRole($this->company, 'warehouse_keeper');
    }

    protected function stockIn(array $extra = []): TestResponse
    {
        return $this->post(route('inventory.stock-in.store'), $extra + [
            'warehouse_id' => $this->warehouse->id, 'receipt_date' => '2026-06-15',
            'lines' => [['product_id' => $this->item->id, 'quantity' => 5]],
        ]);
    }

    public function test_the_keeper_adds_items_and_quantities_only_and_the_stock_enters_at_once(): void
    {
        Notification::fake();
        $accountant = $this->userWithRole($this->company, 'accountant');
        $this->actingAs($this->keeper());

        $this->stockIn()->assertSessionHasNoErrors()->assertRedirect(route('inventory.stock-in.index'));

        $draft = PurchaseDraft::first();
        $this->assertSame('pending', $draft->status);
        $this->assertMatchesRegularExpression('/^PDR-/', $draft->number);
        $receipt = GoodsReceipt::first();
        $this->assertNull($receipt->supplier_id);
        $this->assertSame(5.0, app(StockService::class)->onHand($this->item->id));
        $this->assertSame(50.0, $this->balance('inventory'));   // provisional cost: the item's purchase price
        $this->assertSame(-50.0, $this->balance('grni'));
        $this->assertSame(0, PurchaseInvoice::count());
        Notification::assertSentTo($accountant, ErpAlert::class);

        // The keeper cannot see or complete purchase drafts, nor open invoices.
        $this->get(route('purchases.drafts.index'))->assertForbidden();
        $this->post(route('purchases.drafts.complete', $draft->id), [])->assertForbidden();
    }

    public function test_accounting_completes_the_draft_with_supplier_date_and_real_prices(): void
    {
        $this->actingAs($this->keeper());
        $this->stockIn(['lines' => [['product_id' => $this->item->id, 'quantity' => 4]]]);
        $draft = PurchaseDraft::first();
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $itemId = $draft->receipt->items->first()->id;

        $this->actingAs($this->userWithRole($this->company, 'accountant'));
        $this->get(route('purchases.drafts.complete-form', $draft->id))->assertOk()->assertInertia(fn ($p) => $p->component('Purchases/Drafts/Complete')->where('items.0.provisional_cost', 10));
        $this->post(route('purchases.drafts.complete', $draft->id), [
            'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'INV-77', 'invoice_date' => '2026-06-16', 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'lines' => [['goods_receipt_item_id' => $itemId, 'quantity' => 4, 'unit_price' => 12, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertSame('completed', $draft->status);
        $invoice = PurchaseInvoice::find($draft->purchase_invoice_id);
        $this->assertSame($supplier->id, $invoice->supplier_id);
        $this->assertSame(55.2, round((float) $invoice->total, 2)); // 4 × 12 + 15%
        $this->assertSame('invoiced', $draft->receipt->fresh()->status);
        $this->assertSame($supplier->id, $draft->receipt->fresh()->supplier_id);
        // The stock is now valued at the invoice price (48), the clearing account is empty and the supplier is owed the total.
        $this->assertSame(48.0, $this->balance('inventory'));
        $this->assertSame(0.0, $this->balance('grni'));
        $this->assertSame(55.2, (float) $supplier->fresh()->current_balance);
        // The clearing line of the receipt carries the supplier now.
        $this->assertSame(0, DB::table('journal_entry_lines')->where('credit', '>', 0)->whereNull('party_id')->where('account_id', app(AccountMap::class)->find('grni'))->count());

        // A second completion is refused.
        $this->post(route('purchases.drafts.complete', $draft->id), [
            'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'INV-78', 'invoice_date' => '2026-06-16', 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'lines' => [['goods_receipt_item_id' => $itemId, 'quantity' => 4, 'unit_price' => 12, 'tax_rate' => 15]],
        ])->assertSessionHasErrors('draft');
        $this->assertSame(1, PurchaseInvoice::count());
    }

    public function test_a_wrong_stock_in_is_discarded_and_the_quantity_leaves_stock(): void
    {
        $this->actingAs($this->keeper());
        $this->stockIn();
        $draft = PurchaseDraft::first();
        $this->assertSame(5.0, app(StockService::class)->onHand($this->item->id));

        $this->actingAs($this->userWithRole($this->company, 'accountant'));
        $this->delete(route('purchases.drafts.destroy', $draft->id), ['reason' => 'خطأ إدخال'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->assertSame(0.0, app(StockService::class)->onHand($this->item->id));
        $this->assertSame(0.0, $this->balance('inventory'));
        $this->assertSame(0.0, $this->balance('grni'));
    }

    public function test_services_zero_quantities_and_other_companies_are_refused(): void
    {
        $this->actingAs($this->keeper());
        $service = Product::create(['name_ar' => 'خدمة', 'sku' => 'SV', 'type' => 'service', 'is_active' => true]);
        $this->stockIn(['lines' => [['product_id' => $service->id, 'quantity' => 1]]])->assertSessionHasErrors('lines');
        $this->stockIn(['lines' => [['product_id' => $this->item->id, 'quantity' => 0]]])->assertSessionHasErrors('lines.0.quantity');
        $this->stockIn(['receipt_date' => '2027-01-01'])->assertSessionHasErrors('receipt_date');
        $this->assertSame(0, PurchaseDraft::count());

        $this->stockIn();
        $draft = PurchaseDraft::first();
        $other = Company::factory()->create();
        $this->actingAs($this->userWithRole($other, 'accountant'));
        app(TenantManager::class)->setTenant($other);
        $this->get(route('purchases.drafts.complete-form', $draft->id))->assertNotFound();
    }
}

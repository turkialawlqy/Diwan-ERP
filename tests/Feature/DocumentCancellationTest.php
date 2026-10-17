<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\StockService;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\Sales\CustomerStatement;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesCycleService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cancelling posted documents: the entry is reversed and everything the
 * document moved is put back.
 */
class DocumentCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find(app(AccountMap::class)->id($role, $this->company->id))->balance, 2);
    }

    protected function assertLedgerBalanced(): void
    {
        $totals = DB::table('account_balances')->where('company_id', $this->company->id)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertEqualsWithDelta((float) $totals->d, (float) $totals->c, 0.001);
    }

    protected function stockSetup(): array
    {
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $item = Product::create(['name_ar' => 'صنف', 'sku' => 'S1', 'track_inventory' => true, 'is_active' => true]);

        return [$warehouse, $item, Supplier::create(['name' => 'مورد', 'status' => 'active'])];
    }

    public function test_cancelling_a_cheque_receipt_reopens_the_invoice_and_cancels_the_cheque(): void
    {
        $invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-01'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
        $this->post(route('sales.payments.store'), ['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'amount' => 1150,
            'payment_date' => '2026-06-05', 'payment_method' => 'cheque', 'reference_number' => 'CH-9'])->assertSessionHasNoErrors();
        $payment = CustomerPayment::latest('id')->first();
        $this->assertSame('paid', $invoice->fresh()->payment_status);

        $this->post(route('sales.payments.cancel', $payment), [])->assertSessionHasErrors('reason');
        $this->post(route('sales.payments.cancel', $payment), ['reason' => 'شيك مرفوض من العميل'])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame([0.0, 1150.0, 'unpaid'], [(float) $invoice->paid_amount, (float) $invoice->remaining_amount, $invoice->payment_status]);
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('cancelled', Cheque::where('cheque_number', 'CH-9')->value('status'));
        $this->assertSame(1150.0, $this->balance('receivables'));
        $this->assertSame(0.0, $this->balance('cheques_under_collection'));
        $this->assertSame(1150.0, (float) $this->customer->fresh()->current_balance);
        $this->assertLedgerBalanced();

        // Twice is refused, and the statement no longer shows the receipt.
        $this->post(route('sales.payments.cancel', $payment), ['reason' => 'x'])->assertSessionHasErrors();
        $this->assertSame(1150.0, app(CustomerStatement::class)->build($this->customer->fresh())['closing']);
    }

    public function test_cancelling_a_supplier_payment_restores_what_is_owed(): void
    {
        [, , $supplier] = $this->stockSetup();
        $bill = PurchaseInvoice::create(['supplier_id' => $supplier->id, 'invoice_number' => 'PI-1', 'invoice_date' => '2026-06-01',
            'subtotal' => 1000, 'tax_amount' => 150, 'total' => 1150, 'paid_amount' => 0, 'remaining_amount' => 1150, 'payment_status' => 'unpaid', 'status' => 'approved']);
        app(DocumentPoster::class)->purchaseInvoice($bill);
        $this->post(route('purchases.payments.store'), ['supplier_id' => $supplier->id, 'purchase_invoice_id' => $bill->id, 'amount' => 500,
            'payment_date' => '2026-06-05', 'payment_method' => 'bank_transfer'])->assertSessionHasNoErrors();
        $this->assertSame(650.0, $this->balance('payables'));

        $this->post(route('purchases.payments.cancel', SupplierPayment::latest('id')->first()), ['reason' => 'خطأ في المبلغ'])->assertSessionHasNoErrors();
        $this->assertSame(1150.0, $this->balance('payables'));
        $this->assertSame([0.0, 1150.0, 'unpaid'], [(float) $bill->fresh()->paid_amount, (float) $bill->fresh()->remaining_amount, $bill->fresh()->payment_status]);
        $this->assertLedgerBalanced();
    }

    public function test_cancelling_a_delivery_returns_the_goods_at_their_cost(): void
    {
        [$warehouse, $item] = $this->stockSetup();
        app(StockService::class)->receive(['product_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10, 'unit_cost' => 40, 'type' => 'opening']);
        $note = app(SalesCycleService::class)->deliver(null, ['customer_id' => $this->customer->id, 'warehouse_id' => $warehouse->id, 'delivery_date' => '2026-06-10'],
            [['product_id' => $item->id, 'item_name' => 'صنف', 'quantity' => 4, 'unit_price' => 60]]);
        $this->assertSame(6.0, app(StockService::class)->onHand($item->id, $warehouse->id));

        $this->post(route('sales.deliveries.cancel', $note), ['reason' => 'العميل لم يستلم'])->assertSessionHasNoErrors();
        $this->assertSame(10.0, app(StockService::class)->onHand($item->id, $warehouse->id));
        $this->assertSame('cancelled', $note->fresh()->status);
        $this->assertSame(0.0, $this->balance('cogs'));
        $this->assertSame(400.0, round((float) StockMovement::where('product_id', $item->id)->sum('total_cost'), 2));
        $this->assertLedgerBalanced();
    }

    public function test_cancelling_a_goods_receipt_takes_the_goods_out_and_clears_grni(): void
    {
        [$warehouse, $item, $supplier] = $this->stockSetup();
        $receipt = app(PurchaseCycleService::class)->receive(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'receipt_date' => '2026-06-01'],
            [['product_id' => $item->id, 'item_name' => 'صنف', 'quantity' => 10, 'unit_cost' => 100]]);
        $this->assertSame(1000.0, $this->balance('grni'));

        $this->post(route('purchases.receipts.cancel', $receipt), ['reason' => 'استلام مكرر'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, app(StockService::class)->onHand($item->id, $warehouse->id));
        $this->assertSame([0.0, 0.0], [$this->balance('grni'), $this->balance('inventory')]);
        $this->assertLedgerBalanced();
    }

    public function test_cancelling_a_treasury_transfer(): void
    {
        $cash = BankAccount::create(['name' => 'الصندوق', 'type' => 'cash', 'currency' => 'SAR', 'account_id' => app(AccountMap::class)->id('default_cash'), 'current_balance' => 0, 'is_active' => true]);
        $bank = BankAccount::create(['name' => 'البنك', 'type' => 'bank', 'currency' => 'SAR', 'account_id' => app(AccountMap::class)->id('default_bank'), 'current_balance' => 0, 'is_active' => true]);
        app(PostingService::class)->post(['date' => '2026-06-01', 'description' => 'رصيد', 'entry_type' => 'opening', 'lines' => [
            ['role' => 'default_cash', 'debit' => 5000], ['role' => 'capital', 'credit' => 5000]]]);
        $this->post(route('finance.transfers.store'), ['from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => 2000, 'transfer_date' => '2026-06-02'])->assertSessionHasNoErrors();
        $entry = JournalEntry::where('source_type', 'transfer')->first();

        $this->post(route('finance.transfers.cancel', $entry), ['reason' => 'خطأ'])->assertSessionHasNoErrors();
        $this->assertSame([5000.0, 0.0], [$this->balance('default_cash'), $this->balance('default_bank')]);
        $this->assertSame('reversed', $entry->fresh()->status);
    }
}

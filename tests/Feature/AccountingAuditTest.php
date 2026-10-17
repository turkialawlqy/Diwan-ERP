<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\YearEndClosing;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesCycleService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Accounting review: every test reproduces a finding of the CFO review with
 * numbers and pins the corrected behaviour.
 */
class AccountingAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create(['tax_number' => '300000000000003']);
        app(AccountingSetupService::class)->provision($this->company);
        $this->owner = $this->userWithRole($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->owner);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function role(string $role): int
    {
        return app(AccountMap::class)->id($role, $this->company->id);
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find($this->role($role))->balance, 2);
    }

    protected function assertLedgerBalanced(): void
    {
        $totals = DB::table('account_balances')->where('company_id', $this->company->id)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertEqualsWithDelta((float) $totals->d, (float) $totals->c, 0.001, 'Ledger is not balanced');
    }

    protected function reports(): AccountingReports
    {
        return app(AccountingReports::class);
    }

    // ------------------------------------------------------------ A. reports & closing

    public function test_closing_a_year_keeps_locked_periods_locked(): void
    {
        $year = FiscalYear::firstOrFail();
        FiscalPeriod::where('fiscal_year_id', $year->id)->whereDate('start_date', '2026-01-01')->update(['status' => 'locked']);
        Carbon::setTestNow('2027-01-05 09:00:00');

        app(YearEndClosing::class)->close($year);

        $this->assertSame('locked', FiscalPeriod::where('fiscal_year_id', $year->id)->whereDate('start_date', '2026-01-01')->value('status'));
        $this->assertSame(11, FiscalPeriod::where('fiscal_year_id', $year->id)->where('status', 'closed')->count());
    }

    public function test_gross_profit_deducts_project_costs_and_excludes_other_income(): void
    {
        $posting = app(PostingService::class);
        $posting->post(['date' => '2026-06-01', 'description' => 'إيراد عقد', 'lines' => [['role' => 'default_cash', 'debit' => 10000], ['role' => 'contract_revenue', 'credit' => 10000]]]);
        $posting->post(['date' => '2026-06-02', 'description' => 'تكلفة مشروع', 'lines' => [['role' => 'project_costs', 'debit' => 6000], ['role' => 'default_cash', 'credit' => 6000]]]);
        $posting->post(['date' => '2026-06-03', 'description' => 'إيراد آخر', 'lines' => [['role' => 'default_cash', 'debit' => 500], ['role' => 'other_revenue', 'credit' => 500]]]);

        $income = $this->reports()->incomeStatement(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));

        $this->assertSame(10500.0, $income['total_revenue']);
        $this->assertSame(10000.0, $income['operating_revenue']);
        $this->assertSame(6000.0, $income['cost_of_sales']);
        $this->assertSame(4000.0, $income['gross_profit']); // was 10,500 (project costs ignored, other income included)
        $this->assertSame(4500.0, $income['net_profit']);
    }

    public function test_cash_flow_assigns_each_counterpart_its_signed_amount(): void
    {
        $posting = app(PostingService::class);
        // A loan of 55,000: 50,000 reaches the bank, 5,000 is a prepaid fee.
        $posting->post(['date' => '2026-06-01', 'description' => 'قرض', 'lines' => [
            ['role' => 'default_bank', 'debit' => 50000], ['role' => 'prepaid_expenses', 'debit' => 5000], ['role' => 'long_term_loans', 'credit' => 55000],
        ]]);
        // Opening cash is not a financing inflow.
        $posting->post(['date' => '2026-06-01', 'description' => 'رصيد افتتاحي', 'entry_type' => 'opening', 'lines' => [
            ['role' => 'default_cash', 'debit' => 3000], ['role' => 'opening_balance_equity', 'credit' => 3000],
        ]]);

        $flow = $this->reports()->cashFlow(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));

        $this->assertSame(55000.0, $flow['sections']['financing']['total']); // was 45,833 + 3,000 opening
        $this->assertSame(-5000.0, $flow['sections']['operating']['total']); // was +4,167
        $this->assertSame(50000.0, $flow['net_change']);
        $this->assertSame(3000.0, $flow['opening_cash']);
        $this->assertSame(53000.0, $flow['closing_cash']);
    }

    public function test_vat_return_classifies_by_line_and_includes_debit_notes(): void
    {
        $invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10', 'payment_method' => 'credit'], [
            ['item_name' => 'خدمة خاضعة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15],
            ['item_name' => 'تصدير', 'quantity' => 1, 'unit_price' => 400, 'tax_rate' => 0, 'vat_category' => 'Z', 'vat_exemption_code' => 'VATEX-SA-32'],
            ['item_name' => 'معفى', 'quantity' => 1, 'unit_price' => 300, 'tax_rate' => 0, 'vat_category' => 'E', 'vat_exemption_code' => 'VATEX-SA-29'],
        ], ['issue_stock' => false]);
        app(SalesCycleService::class)->debitNote(['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'debit_note_date' => '2026-06-20',
            'reason' => 'فرق سعر', 'subtotal' => 200, 'tax_rate' => 15]);

        $vat = collect($this->reports()->vatReturn(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')))->all();
        $boxes = collect($vat['boxes'])->keyBy('no');

        $this->assertSame(1000.0, $boxes[1]['amount']); // the mixed invoice is no longer all "standard"
        $this->assertSame(200.0, $boxes[1]['adjustment']);
        $this->assertSame(180.0, $boxes[1]['vat']); // 150 + 30 from the debit note
        $this->assertSame(400.0, $boxes[4]['amount']);
        $this->assertSame(300.0, $boxes[5]['amount']);
        $this->assertSame(180.0, $vat['output_vat']);
        $this->assertTrue($vat['ledger_matches']);
    }

    // ------------------------------------------------------------ B. sales & customers

    public function test_pos_lines_carry_their_net_so_a_return_credits_real_value(): void
    {
        $warehouse = \App\Models\Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $register = \App\Models\PosRegister::create(['name' => 'كاشير 1', 'warehouse_id' => $warehouse->id, 'is_active' => true]);
        $session = \App\Models\PosSession::create(['user_id' => $this->owner->id, 'pos_register_id' => $register->id, 'opening_cash' => 0, 'status' => 'opened', 'opened_at' => now()]);
        $a = \App\Models\Product::create(['name_ar' => 'صنف أ', 'sku' => 'A1', 'selling_price' => 500, 'track_inventory' => false, 'is_active' => true]);
        $b = \App\Models\Product::create(['name_ar' => 'صنف ب', 'sku' => 'B1', 'selling_price' => 1000, 'track_inventory' => false, 'is_active' => true]);

        $this->post(route('pos.order.process'), ['session_id' => $session->id, 'payment_method' => 'cash', 'paid_amount' => 2185, 'discount' => 100, 'items' => [
            ['product_id' => $a->id, 'item_name' => 'صنف أ', 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 7], // a free-typed rate is not kept
            ['product_id' => $b->id, 'item_name' => 'صنف ب', 'quantity' => 1, 'unit_price' => 1000],
        ]])->assertSessionHasNoErrors();

        $invoice = \App\Models\Invoice::with('items')->latest('id')->first();
        $this->assertSame(1900.0, (float) $invoice->subtotal);
        $this->assertSame(285.0, (float) $invoice->tax_amount);
        $this->assertSame(1900.0, round($invoice->items->sum(fn ($i) => (float) $i->subtotal), 2)); // lines add up to the header
        $this->assertSame([15.0, 15.0], $invoice->items->map(fn ($i) => (float) $i->tax_rate)->all());

        $itemA = $invoice->items->firstWhere('product_id', $a->id);
        $note = app(SalesCycleService::class)->returnInvoice($invoice, ['credit_note_date' => '2026-06-15', 'reason' => 'مرتجع', 'refund_method' => 'cash'],
            [['invoice_item_id' => $itemA->id, 'quantity' => 2, 'return_to_stock' => false]]);

        $this->assertSame(950.0, (float) $note->subtotal); // was 0: POS lines had no subtotal
        $this->assertSame(142.5, (float) $note->tax_amount);
        $this->assertLedgerBalanced();
    }

    public function test_amount_credit_note_settles_the_invoice_and_cash_refunds_are_capped_at_payments(): void
    {
        $invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10', 'payment_method' => 'credit'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);

        // Cash back on an unpaid invoice is refused.
        $this->post(route('sales.returns.store'), ['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => '2026-06-12',
            'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'reason' => 'خصم', 'status' => 'refunded', 'refund_method' => 'cash'])
            ->assertSessionHasErrors('refund_method');
        // VAT must be 15% (or zero).
        $this->post(route('sales.returns.store'), ['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => '2026-06-12',
            'subtotal' => 100, 'tax_amount' => 40, 'total' => 140, 'reason' => 'خصم', 'status' => 'approved'])
            ->assertSessionHasErrors('tax_amount');

        $this->post(route('sales.returns.store'), ['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => '2026-06-12',
            'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'reason' => 'خصم', 'status' => 'approved'])->assertSessionHasNoErrors();

        $this->assertSame(1035.0, (float) $invoice->fresh()->remaining_amount); // was still 1,150
        $this->assertSame(1035.0, (float) $this->customer->fresh()->current_balance);
        $this->assertSame(1035.0, $this->balance('receivables'));

        // More credit than the invoice is refused.
        $this->post(route('sales.returns.store'), ['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => '2026-06-13',
            'subtotal' => 1000, 'tax_amount' => 150, 'total' => 1150, 'reason' => 'خصم', 'status' => 'approved'])
            ->assertSessionHasErrors('total');
    }

    public function test_statement_does_not_count_allocated_receipts_twice(): void
    {
        $invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10', 'payment_method' => 'credit'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
        $this->post(route('sales.payments.store'), ['customer_id' => $this->customer->id, 'payment_date' => '2026-06-12', 'amount' => 500,
            'payment_method' => 'cash'])->assertSessionHasNoErrors(); // auto-allocated to the oldest invoice

        $statement = app(\App\Services\Sales\CustomerStatement::class)->build($this->customer->fresh());

        $this->assertSame(650.0, round((float) $statement['closing'], 2)); // was 150: the receipt showed twice
        $this->assertSame((float) $this->customer->fresh()->current_balance, round((float) $statement['closing'], 2));
    }

    public function test_cheque_receipt_waits_under_collection_and_is_tracked(): void
    {
        app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10', 'payment_method' => 'credit'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
        $bankBefore = $this->balance('default_bank');

        $this->post(route('sales.payments.store'), ['customer_id' => $this->customer->id, 'payment_date' => '2026-06-12', 'amount' => 1150,
            'payment_method' => 'cheque', 'reference_number' => 'CHQ-889'])->assertSessionHasNoErrors();

        $this->assertSame($bankBefore, $this->balance('default_bank')); // was debited immediately
        $this->assertSame(1150.0, $this->balance('cheques_under_collection'));
        $cheque = \App\Models\Cheque::firstOrFail();
        $this->assertSame(['incoming', 'pending', 'CHQ-889'], [$cheque->direction, $cheque->status, $cheque->cheque_number]);

        app(\App\Services\Accounting\ChequeService::class)->transition($cheque, 'cleared', Carbon::parse('2026-06-20'));
        $this->assertSame(0.0, $this->balance('cheques_under_collection'));
        $this->assertSame(round($bankBefore + 1150, 2), $this->balance('default_bank'));
        $this->assertLedgerBalanced();
    }

    // ------------------------------------------------------------ C. purchases & inventory

    protected function purchaseSetup(): array
    {
        $warehouse = \App\Models\Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $supplier = \App\Models\Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $item = \App\Models\Product::create(['name_ar' => 'صنف مخزني', 'sku' => 'S1', 'track_inventory' => true, 'is_active' => true]);

        return [$warehouse, $supplier, $item];
    }

    public function test_supplier_payment_after_a_return_closes_the_invoice(): void
    {
        [$warehouse, $supplier, $item] = $this->purchaseSetup();
        $cycle = app(\App\Services\Purchases\PurchaseCycleService::class);
        $receipt = $cycle->receive(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'receipt_date' => '2026-06-01'],
            [['product_id' => $item->id, 'item_name' => 'صنف مخزني', 'quantity' => 10, 'unit_cost' => 100]]);
        $invoice = $cycle->invoiceReceipts(['supplier_id' => $supplier->id, 'invoice_date' => '2026-06-02', 'payment_method' => 'credit'],
            [['goods_receipt_item_id' => $receipt->items->first()->id, 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 15]]);
        $cycle->returnInvoice($invoice, ['return_date' => '2026-06-03', 'reason' => 'تالف', 'warehouse_id' => $warehouse->id],
            [['purchase_invoice_item_id' => $invoice->items->first()->id, 'quantity' => 2]]);
        $this->assertSame(920.0, (float) $invoice->fresh()->remaining_amount);

        $this->post(route('purchases.payments.store'), ['supplier_id' => $supplier->id, 'purchase_invoice_id' => $invoice->id, 'amount' => 920,
            'payment_date' => '2026-06-05', 'payment_method' => 'bank_transfer'])->assertSessionHasNoErrors();

        $this->assertSame(0.0, (float) $invoice->fresh()->remaining_amount); // was 230 "still due"
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame(0.0, $this->balance('payables'));
    }

    public function test_service_lines_skip_grni_and_price_variance_follows_the_stock(): void
    {
        [$warehouse, $supplier, $item] = $this->purchaseSetup();
        $service = \App\Models\Product::create(['name_ar' => 'شحن', 'sku' => 'SV1', 'track_inventory' => false, 'type' => 'service', 'is_active' => true]);
        $cycle = app(\App\Services\Purchases\PurchaseCycleService::class);
        $receipt = $cycle->receive(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'receipt_date' => '2026-06-01'], [
            ['product_id' => $item->id, 'item_name' => 'صنف مخزني', 'quantity' => 10, 'unit_cost' => 100],
            ['product_id' => $service->id, 'item_name' => 'شحن', 'quantity' => 1, 'unit_cost' => 50],
        ]);
        // 4 of the 10 units are sold before the supplier's invoice arrives at 110 instead of 100.
        app(\App\Services\Inventory\StockService::class)->issue(['product_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 4, 'type' => 'sale']);

        $cycle->invoiceReceipts(['supplier_id' => $supplier->id, 'invoice_date' => '2026-06-05', 'payment_method' => 'credit'], [
            ['goods_receipt_item_id' => $receipt->items->firstWhere('product_id', $item->id)->id, 'quantity' => 10, 'unit_price' => 110, 'tax_rate' => 15],
            ['goods_receipt_item_id' => $receipt->items->firstWhere('product_id', $service->id)->id, 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 15],
        ]);

        $this->assertSame(0.0, $this->balance('grni')); // was 50 debit: the service went through GRNI at no value
        $this->assertSame(90.0, $this->balance('purchases_cogs')); // service 50 + variance of the 4 sold units 40
        // 1,000 received + 60 variance on the 6 units held (the test's direct stock issue posts no COGS entry).
        $this->assertSame(1060.0, $this->balance('inventory'));
        $this->assertSame(110.0, round((float) $item->fresh()->average_cost, 2));
        $valuation = (float) \App\Models\StockMovement::where('product_id', $item->id)->sum('total_cost');
        $this->assertSame(660.0, round($valuation, 2)); // the stock report matches the ledger
        $this->assertLedgerBalanced();
    }

    public function test_fifo_receipt_covers_negative_stock_before_creating_a_layer(): void
    {
        [$warehouse, , $item] = $this->purchaseSetup();
        $this->company->setSetting('costing_method', 'fifo');
        $this->company->setSetting('allow_negative_stock', '1');
        $item->update(['purchase_price' => 20]);
        $stock = app(\App\Services\Inventory\StockService::class);

        $early = $stock->issue(['product_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5, 'type' => 'sale']);
        $this->assertSame(100.0, $early); // costed at the purchase price, not zero
        $stock->receive(['product_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10, 'unit_cost' => 20, 'type' => 'purchase']);
        $later = $stock->issue(['product_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5, 'type' => 'sale']);

        $this->assertSame(100.0, $later);
        $this->assertSame(0.0, (float) \App\Models\StockLayer::where('product_id', $item->id)->sum('remaining')); // no phantom layer left
        $this->assertSame(0.0, $stock->onHand($item->id, $warehouse->id));
    }

    public function test_expense_vat_only_when_taxable_with_a_supplier_vat_number(): void
    {
        $this->post(route('finance.expenses.store'), ['title' => 'رسوم حكومية', 'category' => 'رسوم', 'amount' => 1000, 'expense_date' => '2026-06-10', 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->balance('vat_input')); // was 150 claimed on a government fee

        $this->post(route('finance.expenses.store'), ['title' => 'صيانة', 'category' => 'صيانة', 'amount' => 1000, 'taxable' => true, 'expense_date' => '2026-06-10', 'payment_method' => 'cash'])
            ->assertSessionHasErrors('vendor_tax_number');
        $this->post(route('finance.expenses.store'), ['title' => 'صيانة', 'category' => 'صيانة', 'amount' => 1000, 'taxable' => true, 'vendor_tax_number' => '310000000000003',
            'expense_date' => '2026-06-10', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(150.0, $this->balance('vat_input'));
    }
}

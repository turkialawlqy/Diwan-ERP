<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\AssetDepreciation;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Invoice;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StockLayer;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\ChequeService;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\DocumentBackfill;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\YearEndClosing;
use App\Services\HR\PayrollService;
use App\Services\Inventory\StockService;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\Sales\CustomerStatement;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesCycleService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
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
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $register = PosRegister::create(['name' => 'كاشير 1', 'warehouse_id' => $warehouse->id, 'is_active' => true]);
        $session = PosSession::create(['user_id' => $this->owner->id, 'pos_register_id' => $register->id, 'opening_cash' => 0, 'status' => 'opened', 'opened_at' => now()]);
        $a = Product::create(['name_ar' => 'صنف أ', 'sku' => 'A1', 'selling_price' => 500, 'track_inventory' => false, 'is_active' => true]);
        $b = Product::create(['name_ar' => 'صنف ب', 'sku' => 'B1', 'selling_price' => 1000, 'track_inventory' => false, 'is_active' => true]);

        $this->post(route('pos.order.process'), ['session_id' => $session->id, 'payment_method' => 'cash', 'paid_amount' => 2185, 'discount' => 100, 'items' => [
            ['product_id' => $a->id, 'item_name' => 'صنف أ', 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 7], // a free-typed rate is not kept
            ['product_id' => $b->id, 'item_name' => 'صنف ب', 'quantity' => 1, 'unit_price' => 1000],
        ]])->assertSessionHasNoErrors();

        $invoice = Invoice::with('items')->latest('id')->first();
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

        $statement = app(CustomerStatement::class)->build($this->customer->fresh());

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
        $cheque = Cheque::firstOrFail();
        $this->assertSame(['incoming', 'pending', 'CHQ-889'], [$cheque->direction, $cheque->status, $cheque->cheque_number]);

        app(ChequeService::class)->transition($cheque, 'cleared', Carbon::parse('2026-06-20'));
        $this->assertSame(0.0, $this->balance('cheques_under_collection'));
        $this->assertSame(round($bankBefore + 1150, 2), $this->balance('default_bank'));
        $this->assertLedgerBalanced();
    }

    // ------------------------------------------------------------ C. purchases & inventory

    protected function purchaseSetup(): array
    {
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $item = Product::create(['name_ar' => 'صنف مخزني', 'sku' => 'S1', 'track_inventory' => true, 'is_active' => true]);

        return [$warehouse, $supplier, $item];
    }

    public function test_supplier_payment_after_a_return_closes_the_invoice(): void
    {
        [$warehouse, $supplier, $item] = $this->purchaseSetup();
        $cycle = app(PurchaseCycleService::class);
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
        $service = Product::create(['name_ar' => 'شحن', 'sku' => 'SV1', 'track_inventory' => false, 'type' => 'service', 'is_active' => true]);
        $cycle = app(PurchaseCycleService::class);
        $receipt = $cycle->receive(null, ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'receipt_date' => '2026-06-01'], [
            ['product_id' => $item->id, 'item_name' => 'صنف مخزني', 'quantity' => 10, 'unit_cost' => 100],
            ['product_id' => $service->id, 'item_name' => 'شحن', 'quantity' => 1, 'unit_cost' => 50],
        ]);
        // 4 of the 10 units are sold before the supplier's invoice arrives at 110 instead of 100.
        app(StockService::class)->issue(['product_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 4, 'type' => 'sale']);

        $cycle->invoiceReceipts(['supplier_id' => $supplier->id, 'invoice_date' => '2026-06-05', 'payment_method' => 'credit'], [
            ['goods_receipt_item_id' => $receipt->items->firstWhere('product_id', $item->id)->id, 'quantity' => 10, 'unit_price' => 110, 'tax_rate' => 15],
            ['goods_receipt_item_id' => $receipt->items->firstWhere('product_id', $service->id)->id, 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 15],
        ]);

        $this->assertSame(0.0, $this->balance('grni')); // was 50 debit: the service went through GRNI at no value
        $this->assertSame(90.0, $this->balance('purchases_cogs')); // service 50 + variance of the 4 sold units 40
        // 1,000 received + 60 variance on the 6 units held (the test's direct stock issue posts no COGS entry).
        $this->assertSame(1060.0, $this->balance('inventory'));
        $this->assertSame(110.0, round((float) $item->fresh()->average_cost, 2));
        $valuation = (float) StockMovement::where('product_id', $item->id)->sum('total_cost');
        $this->assertSame(660.0, round($valuation, 2)); // the stock report matches the ledger
        $this->assertLedgerBalanced();
    }

    public function test_fifo_receipt_covers_negative_stock_before_creating_a_layer(): void
    {
        [$warehouse, , $item] = $this->purchaseSetup();
        $this->company->setSetting('costing_method', 'fifo');
        $this->company->setSetting('allow_negative_stock', '1');
        $item->update(['purchase_price' => 20]);
        $stock = app(StockService::class);

        $early = $stock->issue(['product_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5, 'type' => 'sale']);
        $this->assertSame(100.0, $early); // costed at the purchase price, not zero
        $stock->receive(['product_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10, 'unit_cost' => 20, 'type' => 'purchase']);
        $later = $stock->issue(['product_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5, 'type' => 'sale']);

        $this->assertSame(100.0, $later);
        $this->assertSame(0.0, (float) StockLayer::where('product_id', $item->id)->sum('remaining')); // no phantom layer left
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

    // ------------------------------------------------------------ D. ledger, assets, equipment, payroll

    protected function journal(array $lines): TestResponse
    {
        return $this->post(route('accounting.journals.store'), ['date' => '2026-06-10', 'description' => 'قيد يدوي', 'save_as' => 'posted', 'lines' => $lines]);
    }

    public function test_manual_journals_name_the_party_and_keep_document_accounts_closed(): void
    {
        $line = fn (string $role, float $debit, float $credit, array $extra = []) => array_merge(['account_id' => $this->role($role), 'debit' => $debit, 'credit' => $credit], $extra);

        $this->journal([$line('receivables', 500, 0), $line('other_revenue', 0, 500)])->assertSessionHasErrors('lines.0.party_id');
        $this->journal([$line('receivables', 500, 0, ['party_type' => 'customer', 'party_id' => $this->customer->id]), $line('other_revenue', 0, 500)])
            ->assertSessionHasNoErrors();
        $this->assertSame(500.0, (float) DB::table('journal_entry_lines')->where('party_type', 'customer')->where('party_id', $this->customer->id)->sum('debit'));

        // An accountant without "manage journals" cannot touch inventory by hand.
        $accountant = $this->userWithRole($this->company, 'accountant');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $accountant->roles->first()->revokePermissionTo('accounting.journals.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($accountant->fresh());
        $this->journal([$line('inventory', 100, 0), $line('capital', 0, 100)])->assertSessionHasErrors('lines.0.account_id');
    }

    public function test_account_type_is_locked_once_posted_and_roles_need_the_right_type(): void
    {
        $account = Account::find($this->role('other_revenue'));
        $this->journal([['account_id' => $this->role('default_cash'), 'debit' => 100, 'credit' => 0], ['account_id' => $account->id, 'debit' => 0, 'credit' => 100]]);

        $this->put(route('accounting.accounts.update', $account), ['code' => $account->code, 'name_ar' => $account->name_ar, 'type' => 'liability', 'nature' => 'credit'])
            ->assertSessionHas('error');
        $this->assertSame('revenue', $account->fresh()->type);

        $this->put(route('accounting.mappings.update'), ['mapping' => ['sales_revenue' => $this->role('default_cash')]])->assertSessionHasErrors('mapping.sales_revenue');
    }

    protected function assetCategory(): AssetCategory
    {
        return AssetCategory::create(['name_ar' => 'معدات', 'useful_life_months' => 60, 'depreciation_method' => 'straight_line',
            'asset_account_id' => $this->role('fixed_assets'), 'accumulated_account_id' => $this->role('accumulated_depreciation'), 'expense_account_id' => $this->role('depreciation_expense')]);
    }

    public function test_opening_asset_brings_its_accumulated_depreciation_and_missed_months_catch_up(): void
    {
        $category = $this->assetCategory();
        $this->post(route('accounting.fixed-assets.store'), ['asset_category_id' => $category->id, 'code' => 'FA-OLD', 'name_ar' => 'رافعة',
            'acquisition_date' => '2022-01-01', 'depreciation_start_date' => '2026-04-01', 'cost' => 60000, 'useful_life_months' => 60,
            'paid_from' => 'opening', 'opening_accumulated' => 50000])->assertSessionHasNoErrors();
        $asset = FixedAsset::where('code', 'FA-OLD')->first();

        $this->assertSame(50000.0, (float) $asset->accumulated_depreciation);
        $this->assertSame(50000.0, $this->balance('accumulated_depreciation')); // credit-nature contra account
        $this->assertSame(10000.0, $this->balance('opening_balance_equity'));

        // Nobody ran April and May: running June posts all three months.
        $result = app(DepreciationService::class)->run(Carbon::parse('2026-06-30'));
        $this->assertSame(3000.0, $result['amount']);
        $this->assertSame(3, AssetDepreciation::where('fixed_asset_id', $asset->id)->count());
        $this->assertSame(53000.0, (float) $asset->fresh()->accumulated_depreciation);
        $this->assertLedgerBalanced();
    }

    public function test_fixed_asset_vat_on_purchase_and_sale_reaches_the_vat_return(): void
    {
        $category = $this->assetCategory();
        $this->post(route('accounting.fixed-assets.store'), ['asset_category_id' => $category->id, 'code' => 'FA-1', 'name_ar' => 'سيارة',
            'acquisition_date' => '2026-06-01', 'cost' => 100000, 'acquisition_vat' => 15000, 'paid_from' => 'bank_transfer'])->assertSessionHasNoErrors();
        $this->post(route('accounting.fixed-assets.store'), ['asset_category_id' => $category->id, 'code' => 'FA-2', 'name_ar' => 'سيارة',
            'acquisition_date' => '2026-06-01', 'cost' => 1000, 'acquisition_vat' => 99, 'paid_from' => 'bank_transfer'])->assertSessionHasErrors('acquisition_vat');
        $this->assertSame(15000.0, $this->balance('vat_input'));

        $asset = FixedAsset::where('code', 'FA-1')->first();
        $this->post(route('accounting.fixed-assets.dispose', $asset), ['date' => '2026-06-20', 'proceeds' => 90000, 'vat_amount' => 13500, 'method' => 'bank_transfer'])
            ->assertSessionHasNoErrors();
        $this->assertSame(13500.0, $this->balance('vat_output'));

        $boxes = collect($this->reports()->vatReturn(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'))['boxes'])->keyBy('no');
        $this->assertSame(13500.0, $boxes[1]['vat']);
        $this->assertSame(15000.0, $boxes[7]['vat']);
        $this->assertLedgerBalanced();
    }

    public function test_equipment_charged_to_projects_is_a_contra_expense_and_old_accounts_are_reclassified(): void
    {
        $account = Account::find($this->role('equipment_recovery'));
        $this->assertSame(['expense', 'credit'], [$account->type, $account->nature]);

        // A company provisioned before the fix: the unused revenue account is reclassified in place.
        $account->update(['type' => 'revenue', 'code' => '49']);
        (include base_path('database/migrations/2026_10_18_000002_accounting_review_ledger_fixes.php'))->up();
        $account->refresh();
        $this->assertSame(['expense', 'credit', '59'], [$account->type, $account->nature, $account->code]);
        $this->assertSame($account->id, $this->role('equipment_recovery'));
    }

    public function test_cash_box_balance_and_transfer_check_follow_the_ledger(): void
    {
        $cash = BankAccount::create(['name' => 'الصندوق', 'type' => 'cash', 'currency' => 'SAR', 'account_id' => $this->role('default_cash'), 'current_balance' => 0, 'is_active' => true]);
        $bank = BankAccount::create(['name' => 'البنك', 'type' => 'bank', 'currency' => 'SAR', 'account_id' => $this->role('default_bank'), 'current_balance' => 0, 'is_active' => true]);
        app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10', 'payment_method' => 'cash', 'payment_status' => 'paid', 'bank_account_id' => $cash->id],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);

        $this->assertSame(1150.0, $cash->fresh()->ledgerBalance()); // the stored current_balance is still 0
        $this->post(route('finance.transfers.store'), ['from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => 1000, 'transfer_date' => '2026-06-11'])
            ->assertSessionHasNoErrors();
        $this->post(route('finance.transfers.store'), ['from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => 500, 'transfer_date' => '2026-06-11'])
            ->assertSessionHasErrors('amount');
        $this->assertSame(150.0, $this->balance('default_cash'));
    }

    public function test_backfill_does_not_post_a_later_receipt_twice(): void
    {
        $invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-01'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false, 'post' => false]);
        $this->post(route('sales.payments.store'), ['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'amount' => 1150,
            'payment_date' => '2026-06-05', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(1150.0, (float) $invoice->fresh()->paid_amount);

        app(DocumentBackfill::class)->run($this->company);

        $this->assertSame(0.0, $this->balance('receivables'));
        $this->assertSame(1150.0, $this->balance('default_cash')); // was 2,300: invoice "paid part" + the receipt
        $this->assertSame(1000.0, $this->balance('sales_revenue'));
        $this->assertLedgerBalanced();
    }

    public function test_payroll_fines_are_held_for_the_workers_fund(): void
    {
        $employee = Employee::create(['name' => 'أحمد', 'job_title' => 'فني', 'department' => 'التشغيل', 'hire_date' => '2020-01-01', 'nationality' => 'IN',
            'basic_salary' => 3000, 'housing_allowance' => 0, 'transport_allowance' => 0, 'other_allowances' => 0, 'total_salary' => 3000,
            'status' => 'active', 'gosi_registered' => false, 'employee_number' => 'E100']);
        EmployeeAdjustment::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 5, 'type' => 'penalty', 'amount' => 200]);

        $payroll = app(PayrollService::class)->generate(2026, 5);
        app(PayrollService::class)->approve($payroll);

        $this->assertSame(200.0, $this->balance('workers_fund'));
        $this->assertSame((float) $payroll->items()->first()->gross_salary, $this->balance('salaries_expense')); // not reduced by the fine
        $this->assertSame(round((float) $payroll->items()->first()->gross_salary - 200, 2), $this->balance('salaries_payable'));
        $this->assertLedgerBalanced();
    }
}

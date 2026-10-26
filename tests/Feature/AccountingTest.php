<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\Budget;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\ChequeService;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\YearEndClosing;
use App\Services\Inventory\StockService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 10:00:00');

        $this->company = Company::factory()->create(['tax_number' => '300000000000003']);
        app(AccountingSetupService::class)->provision($this->company);
        $this->owner = $this->userWithRole($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->owner);
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
        return (float) Account::find($this->role($role))->balance;
    }

    protected function assertLedgerBalanced(): void
    {
        $totals = DB::table('account_balances')->where('company_id', $this->company->id)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertEqualsWithDelta((float) $totals->d, (float) $totals->c, 0.001, 'Ledger is not balanced');
    }

    protected function customer(): Customer
    {
        return Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
    }

    public function test_new_company_gets_chart_mappings_and_fiscal_year(): void
    {
        $this->assertGreaterThan(50, Account::count());
        foreach (array_keys(AccountMap::ROLES) as $role) {
            $this->assertNotNull(app(AccountMap::class)->find($role), "Role {$role} not mapped");
        }
        $this->assertTrue(FiscalYear::whereDate('start_date', '2026-01-01')->exists());
        $this->assertSame(12, FiscalPeriod::count());
    }

    public function test_posting_rejects_unbalanced_parent_and_closed_period(): void
    {
        $posting = app(PostingService::class);
        $cash = $this->role('default_cash');
        $sales = $this->role('sales_revenue');

        try {
            $posting->post(['date' => now(), 'entry_type' => 'manual', 'description' => 'x', 'lines' => [
                ['account_id' => $cash, 'debit' => 100], ['account_id' => $sales, 'credit' => 90],
            ]]);
            $this->fail('Unbalanced entry was accepted');
        } catch (AccountingException) {
        }

        $parent = Account::where('code', '11')->first();
        $this->expectException(AccountingException::class);
        $posting->post(['date' => now(), 'description' => 'x', 'lines' => [
            ['account_id' => $parent->id, 'debit' => 100], ['account_id' => $sales, 'credit' => 100],
        ]]);
    }

    public function test_closed_period_blocks_posting(): void
    {
        FiscalPeriod::where('name', '2026-06')->update(['status' => 'closed']);

        $this->expectException(AccountingException::class);
        app(PostingService::class)->post(['date' => '2026-06-10', 'description' => 'x', 'lines' => [
            ['role' => 'default_cash', 'debit' => 10], ['role' => 'sales_revenue', 'credit' => 10],
        ]]);
    }

    public function test_balances_and_reversal(): void
    {
        $posting = app(PostingService::class);
        $entry = $posting->post(['date' => now(), 'description' => 'بيع', 'lines' => [
            ['role' => 'default_cash', 'debit' => 1000], ['role' => 'sales_revenue', 'credit' => 1000],
        ]]);

        $this->assertSame(1000.0, $this->balance('default_cash'));
        $this->assertSame(1000.0, $this->balance('sales_revenue'));

        $posting->reverse($entry);

        $this->assertSame(0.0, $this->balance('default_cash'));
        $this->assertSame('reversed', $entry->fresh()->status);
        $this->assertLedgerBalanced();
    }

    public function test_sales_invoice_posts_receivable_revenue_vat_and_cost(): void
    {
        $customer = $this->customer();
        $product = Product::create(['name_ar' => 'منتج', 'sku' => 'P1', 'selling_price' => 100, 'purchase_price' => 60, 'track_inventory' => true, 'is_active' => true]);
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10, 'unit_cost' => 60, 'type' => 'opening']);

        $this->post(route('sales.invoices.store'), [
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-15',
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'items' => [['product_id' => $product->id, 'item_name' => 'منتج', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::firstOrFail();
        $this->assertStringStartsWith('INV-26-', $invoice->invoice_number);
        $this->assertSame(230.0, $this->balance('receivables'));
        $this->assertSame(200.0, $this->balance('sales_revenue'));
        $this->assertSame(30.0, $this->balance('vat_output'));
        $this->assertSame(120.0, $this->balance('cogs'));
        $this->assertSame(-120.0, $this->balance('inventory'));

        $entry = JournalEntry::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->firstOrFail();
        $this->assertTrue($entry->lines->contains(fn ($l) => $l->party_type === 'customer' && $l->party_id === $customer->id));
        $this->assertLedgerBalanced();
    }

    public function test_customer_payment_supplier_bill_expense_and_transfer_are_posted(): void
    {
        $customer = $this->customer();
        $this->post(route('sales.payments.store'), [
            'customer_id' => $customer->id, 'payment_date' => '2026-06-15', 'amount' => 500, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $this->assertSame(500.0, $this->balance('default_cash'));
        $this->assertSame(-500.0, $this->balance('receivables'));

        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_active' => true]);
        $this->post(route('purchases.invoices.store'), [
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-06-15',
            'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'items' => [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1150.0, $this->balance('payables'));
        $this->assertSame(150.0, $this->balance('vat_input'));
        $this->assertSame(1000.0, $this->balance('purchases_cogs'));

        $this->post(route('finance.expenses.store'), [
            'title' => 'إيجار', 'category' => 'rent', 'amount' => 200, 'tax_amount' => 0, 'expense_date' => '2026-06-15', 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $this->assertSame(200.0, $this->balance('general_expense'));
        $this->assertSame(300.0, $this->balance('default_cash'));

        // Deleting the expense reverses its entry.
        $this->delete(route('finance.expenses.destroy', Expense::firstOrFail()->id))->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->balance('general_expense'));
        $this->assertSame(500.0, $this->balance('default_cash'));

        $this->assertLedgerBalanced();
    }

    public function test_reports_trial_balance_statements_and_partial_months(): void
    {
        $posting = app(PostingService::class);
        $posting->post(['date' => '2026-02-10', 'description' => 'رأس مال', 'lines' => [['role' => 'default_bank', 'debit' => 10000], ['role' => 'capital', 'credit' => 10000]]]);
        $posting->post(['date' => '2026-03-05', 'description' => 'بيع', 'lines' => [['role' => 'default_cash', 'debit' => 2300], ['role' => 'sales_revenue', 'credit' => 2000], ['role' => 'vat_output', 'credit' => 300]]]);
        $posting->post(['date' => '2026-03-20', 'description' => 'رواتب', 'lines' => [['role' => 'salaries_expense', 'debit' => 800], ['role' => 'default_bank', 'credit' => 800]]]);

        $reports = app(AccountingReports::class);

        $tb = $reports->trialBalance(Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30'));
        $this->assertEqualsWithDelta($tb['totals']['closing_debit'], $tb['totals']['closing_credit'], 0.001);

        // Partial month ranges must match exact line sums.
        $partial = $reports->totalsBetween(Carbon::parse('2026-03-10'), Carbon::parse('2026-03-25'));
        $this->assertEquals(800.0, $partial[$this->role('salaries_expense')]['debit']);
        $this->assertArrayNotHasKey($this->role('sales_revenue'), $partial);

        $income = $reports->incomeStatement(Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30'));
        $this->assertSame(2000.0, $income['total_revenue']);
        $this->assertSame(800.0, $income['total_expense']);
        $this->assertSame(1200.0, $income['net_profit']);

        $sheet = $reports->balanceSheet(Carbon::parse('2026-06-30'));
        $this->assertTrue($sheet['is_balanced']);
        $this->assertSame(11500.0, $sheet['total_assets']);
        $this->assertSame(1200.0, $sheet['unclosed_profit']);

        $cash = $reports->cashFlow(Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30'));
        $this->assertSame(11500.0, $cash['net_change']);

        $vat = $reports->vatReturn(Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30'));
        $this->assertSame(300.0, $vat['ledger_output_vat']);

        // Pages render.
        $this->get(route('accounting.trial-balance.index'))->assertOk();
        $this->get(route('accounting.reports.income-statement'))->assertOk();
        $this->get(route('accounting.reports.balance-sheet'))->assertOk();
        $this->get(route('accounting.reports.cash-flow'))->assertOk();
        $this->get(route('accounting.reports.aging'))->assertOk();
        $this->get(route('accounting.reports.vat-return'))->assertOk();
        $this->get(route('accounting.ledger.index', ['account_id' => $this->role('default_bank')]))->assertOk();
    }

    public function test_year_end_closing_moves_profit_to_retained_earnings(): void
    {
        $posting = app(PostingService::class);
        $posting->post(['date' => '2026-03-05', 'description' => 'بيع', 'lines' => [['role' => 'default_cash', 'debit' => 5000], ['role' => 'sales_revenue', 'credit' => 5000]]]);
        $posting->post(['date' => '2026-04-05', 'description' => 'مصروف', 'lines' => [['role' => 'rent_expense', 'debit' => 2000], ['role' => 'default_cash', 'credit' => 2000]]]);

        $year = FiscalYear::firstOrFail();
        $this->assertThrows(fn () => app(YearEndClosing::class)->close($year), AccountingException::class); // the year has not ended
        Carbon::setTestNow('2027-01-10 09:00:00');
        app(YearEndClosing::class)->close($year);

        $this->assertSame(0.0, $this->balance('sales_revenue'));
        // The closed year still reports its profit (the closing entry is not a result).
        $income = app(AccountingReports::class)->incomeStatement(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));
        $this->assertSame(5000.0, $income['total_revenue']);
        $this->assertSame(3000.0, $income['net_profit']);
        $this->assertSame(0.0, $this->balance('rent_expense'));
        $this->assertSame(3000.0, $this->balance('retained_earnings'));
        $this->assertSame(0, FiscalPeriod::where('fiscal_year_id', $year->id)->where('status', 'open')->count());

        app(YearEndClosing::class)->reopen($year->fresh());
        $this->assertSame(5000.0, $this->balance('sales_revenue'));
        $this->assertLedgerBalanced();
    }

    public function test_depreciation_is_monthly_and_idempotent(): void
    {
        $category = AssetCategory::create([
            'name_ar' => 'حاسبات', 'useful_life_months' => 36, 'depreciation_method' => 'straight_line',
            'asset_account_id' => $this->role('fixed_assets'),
            'accumulated_account_id' => $this->role('accumulated_depreciation'),
            'expense_account_id' => $this->role('depreciation_expense'),
        ]);
        $asset = FixedAsset::create([
            'asset_category_id' => $category->id, 'code' => 'FA-1', 'name_ar' => 'لابتوب',
            'acquisition_date' => '2026-01-01', 'depreciation_start_date' => '2026-01-01',
            'cost' => 3600, 'salvage_value' => 0, 'useful_life_months' => 36, 'depreciation_method' => 'straight_line', 'status' => 'active',
        ]);

        $service = app(DepreciationService::class);
        $service->acquire($asset, 'bank_transfer');
        $first = $service->run(Carbon::parse('2026-01-31'));
        $again = $service->run(Carbon::parse('2026-01-31'));

        $this->assertSame(1, $first['assets']);
        $this->assertSame(0, $again['assets']);
        $this->assertSame(100.0, (float) $asset->fresh()->accumulated_depreciation);
        $this->assertSame(100.0, $this->balance('depreciation_expense'));

        $service->dispose($asset->fresh(), Carbon::parse('2026-02-15'), 4000, null, 'cash');
        $this->assertSame('disposed', $asset->fresh()->status);
        // February is depreciated to the 15th first (100 x 15/28), then gain = 4000 - (3600 - 153.57).
        $this->assertSame(153.57, (float) $asset->fresh()->accumulated_depreciation);
        $this->assertSame(553.57, $this->balance('asset_disposal_gain'));
        $this->assertLedgerBalanced();
    }

    public function test_cheque_life_cycle(): void
    {
        $customer = $this->customer();
        $bank = BankAccount::create(['name' => 'بنك', 'type' => 'bank', 'currency' => 'SAR', 'account_id' => $this->role('default_bank'), 'is_active' => true]);

        $service = app(ChequeService::class);
        $cheque = $service->register([
            'direction' => 'incoming', 'cheque_number' => '123', 'party_type' => 'customer', 'party_id' => $customer->id,
            'party_name' => $customer->name, 'amount' => 700, 'issue_date' => '2026-06-01', 'due_date' => '2026-06-30', 'bank_account_id' => $bank->id,
        ]);

        $this->assertSame(700.0, $this->balance('cheques_under_collection'));
        $service->transition($cheque, 'cleared', Carbon::parse('2026-06-30'));
        $this->assertSame(0.0, $this->balance('cheques_under_collection'));
        $this->assertSame(700.0, $this->balance('default_bank'));

        $this->expectException(AccountingException::class);
        $service->transition($cheque->fresh(), 'bounced', Carbon::parse('2026-06-30'));
    }

    public function test_draft_journal_and_rebuild_balances(): void
    {
        $this->post(route('accounting.journals.store'), [
            'date' => '2026-06-10', 'description' => 'مسودة', 'save_as' => 'draft',
            'lines' => [
                ['account_id' => $this->role('default_cash'), 'debit' => 50, 'credit' => 0],
                ['account_id' => $this->role('other_revenue'), 'debit' => 0, 'credit' => 50],
            ],
        ])->assertSessionHasNoErrors();

        $draft = JournalEntry::where('status', 'draft')->firstOrFail();
        $this->assertSame(0.0, $this->balance('default_cash'));

        $this->post(route('accounting.journals.post', $draft->id))->assertSessionHasNoErrors();
        $this->assertSame(50.0, $this->balance('default_cash'));

        // Rebuilding from the ledger reproduces the same balances.
        app(PostingService::class)->rebuildBalances($this->company->id);
        $this->assertSame(50.0, $this->balance('default_cash'));
        $this->assertLedgerBalanced();
    }

    public function test_fiscal_calendar_respects_start_month(): void
    {
        $this->company->setSetting('fiscal_year_start_month', '7', 'accounting', 'integer');
        $start = app(FiscalCalendar::class)->yearStartFor(Carbon::parse('2027-03-01'), $this->company->id);

        $this->assertSame('2026-07-01', $start->toDateString());
    }

    public function test_accounting_management_pages_render(): void
    {
        foreach ([
            'accounting.journals.index', 'accounting.recurring.index', 'accounting.cost-centers.index',
            'accounting.fiscal-years.index', 'accounting.mappings.index', 'accounting.fixed-assets.index',
            'accounting.cheques.index', 'accounting.reconciliations.index', 'accounting.budgets.index',
            'accounting.accounts.index',
        ] as $name) {
            $this->get(route($name))->assertOk();
        }

        $this->post(route('accounting.budgets.store'), ['name' => 'موازنة 2026', 'fiscal_year_id' => FiscalYear::first()->id]);
        $this->get(route('accounting.budgets.show', Budget::first()->id))->assertOk();

        $bank = BankAccount::create(['name' => 'بنك', 'type' => 'bank', 'currency' => 'SAR', 'account_id' => $this->role('default_bank'), 'is_active' => true]);
        $this->post(route('accounting.reconciliations.store'), ['bank_account_id' => $bank->id, 'statement_date' => '2026-06-30', 'statement_balance' => 0]);
        $this->get(route('accounting.reconciliations.show', BankReconciliation::first()->id))->assertOk();
    }
}

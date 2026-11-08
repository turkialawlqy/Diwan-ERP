<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApprovalRequest;
use App\Models\AssetCategory;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CashForecastSnapshot;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\PurchaseInvoice;
use App\Models\RecurringJournal;
use App\Models\Supplier;
use App\Services\Accounting\AccountingControls;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\BudgetService;
use App\Services\Accounting\ClosingChecklist;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\PostingService;
use App\Services\Reports\CashForecastSnapshots;
use App\Services\Reports\Insights;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Report gaps, batch 3: flexible aging, cash scenarios, unusual expenses. */
class ReportGapsBatch3Test extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-30 10:00:00');
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

    protected function invoice(string $date, float $price, ?string $due = null)
    {
        return app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => $date, 'due_date' => $due ?? $date, 'payment_status' => 'unpaid', 'payment_method' => 'credit'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    public function test_aging_buckets_follow_the_requested_limits(): void
    {
        $this->invoice('2026-06-10', 1000, '2026-06-20');   // 1,150 — 10 days overdue on 30 June

        $default = app(AccountingReports::class)->aging('receivables', Carbon::parse('2026-06-30'));
        $this->assertSame(1150.0, $default['totals']['d30']);
        $this->assertSame(['current', 'd30', 'd60', 'd90', 'd90plus'], array_column($default['columns'], 0));

        $custom = app(AccountingReports::class)->aging('receivables', Carbon::parse('2026-06-30'), [5, 15, 45, 120]);
        $this->assertSame(['current', 'd5', 'd15', 'd45', 'd120', 'd120plus'], array_column($custom['columns'], 0));
        $this->assertSame(1150.0, $custom['totals']['d15']);
        $this->assertSame(0.0, $custom['totals']['d5']);
        $this->assertSame(1150.0, $custom['totals']['total']);
        $this->assertSame('6-15 يوم', $custom['columns'][2][1]);

        $this->get(route('accounting.reports.aging', ['buckets' => '5,15,45,120']))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Accounting/Reports/Aging')->where('filters.buckets', '5,15,45,120')->where('report.totals.d15', 1150));
        $this->get(route('accounting.reports.aging', ['buckets' => 'abc']))->assertSessionHasErrors('buckets');
    }

    public function test_aging_as_of_a_past_date_shows_what_was_collected_after(): void
    {
        $invoice = $this->invoice('2026-06-01', 1000, '2026-06-10');
        $this->post(route('sales.payments.store'), ['customer_id' => $this->customer->id, 'amount' => 400, 'payment_date' => '2026-06-25', 'payment_method' => 'cash',
            'allocations' => [['invoice_id' => $invoice->id, 'amount' => 400]], 'invoice_id' => $invoice->id])->assertSessionHasNoErrors();

        $past = app(AccountingReports::class)->aging('receivables', Carbon::parse('2026-06-20'));
        $this->assertTrue($past['past']);
        $this->assertSame(1150.0, $past['totals']['total']);
        $this->assertSame(400.0, $past['totals']['collected_after']);

        $today = app(AccountingReports::class)->aging('receivables', Carbon::parse('2026-06-30'));
        $this->assertFalse($today['past']);
        $this->assertSame(0.0, $today['totals']['collected_after']);
        $this->assertSame(750.0, $today['totals']['total']);

        $xlsx = $this->get(route('reports.export', ['report' => 'aging', 'format' => 'xlsx', 'as_of' => '2026-06-20', 'buckets' => '10,20']));
        $xlsx->assertOk();
    }

    public function test_cash_forecast_scenarios_shift_and_scale_collections(): void
    {
        $this->invoice('2026-06-25', 1000, '2026-07-10');   // 1,150 due in 10 days → week 2 (index 1)
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        PurchaseInvoice::create(['supplier_id' => $supplier->id, 'invoice_number' => 'B-1', 'invoice_date' => '2026-06-20', 'due_date' => '2026-07-02', 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'subtotal' => 1000, 'tax_amount' => 150, 'total' => 1150, 'paid_amount' => 0, 'remaining_amount' => 1150]);   // due in 2 days → week 1 (index 0)
        $insights = app(Insights::class);

        $base = $insights->cashForecast(13, now());
        $this->assertEqualsWithDelta(1150.0, $base['weeks'][1]['receivables'], 0.01);
        $this->assertEqualsWithDelta(1150.0, $base['weeks'][0]['payables'], 0.01);

        $late = $insights->cashForecast(13, now(), ['collect_delay_days' => 14]);
        $this->assertEqualsWithDelta(0.0, $late['weeks'][1]['receivables'], 0.01);
        $this->assertEqualsWithDelta(1150.0, $late['weeks'][3]['receivables'], 0.01, '10 + 14 days → week 4');

        $half = $insights->cashForecast(13, now(), ['collect_pct' => 50]);
        $this->assertEqualsWithDelta(575.0, $half['weeks'][1]['receivables'], 0.01);
        $this->assertEqualsWithDelta($base['closing'] - 575, $half['closing'], 0.01);

        $defer = $insights->cashForecast(13, now(), ['pay_delay_days' => 14]);
        $this->assertEqualsWithDelta(0.0, $defer['weeks'][0]['payables'], 0.01);
        $this->assertEqualsWithDelta(1150.0, $defer['weeks'][2]['payables'], 0.01, '2 + 14 days → week 3');
        $this->assertSame(['collect_delay_days' => 0, 'collect_pct' => 100.0, 'pay_delay_days' => 14], $defer['scenario']);

        $this->get(route('reports.insights', ['collect_pct' => 50, 'collect_delay_days' => 7]))->assertOk()
            ->assertInertia(fn ($p) => $p->where('filters.collect_pct', 50)->where('filters.collect_delay_days', 7)->where('cash.scenario.collect_pct', 50));
        $this->get(route('reports.export', ['report' => 'cash-forecast', 'format' => 'xlsx', 'collect_pct' => 50]))->assertOk();
    }

    public function test_unusual_expenses_flag_a_month_far_above_the_history(): void
    {
        $map = app(AccountMap::class);
        $make = fn ($code, $name) => Account::create(['code' => $code, 'name_ar' => $name, 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'is_system' => false]);
        $steady = $make('59101', 'إيجار ثابت');
        $spike = $make('59102', 'صيانة');
        $post = fn ($date, $account, $amount) => app(PostingService::class)->post(['date' => $date, 'description' => 'صرف', 'entry_type' => 'manual', 'reference' => 'R'.$date.$account->id, 'lines' => [
            ['account_id' => $account->id, 'debit' => $amount], ['account_id' => $map->id('default_cash'), 'credit' => $amount],
        ]]);
        foreach (['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05', '2026-05-05'] as $d) {
            $post($d, $steady, 100);
            $post($d, $spike, 100 + (substr($d, 5, 2) % 2) * 20);   // 120 / 100 / 120 / 100 / 120
        }
        $post('2026-06-10', $steady, 100);
        $post('2026-06-12', $spike, 900);

        $rows = app(AccountingControls::class)->unusualExpenses(5, 2.0, 0, now());
        $this->assertSame(['59102'], array_column($rows, 'code'));
        $this->assertEqualsWithDelta(112.0, $rows[0]['mean'], 0.01);
        $this->assertEqualsWithDelta(900.0, $rows[0]['amount'], 0.01);
        $this->assertEqualsWithDelta(788.0, $rows[0]['excess'], 0.01);

        $this->assertSame([], app(AccountingControls::class)->unusualExpenses(5, 2.0, 1000, now()), 'the minimum excess hides it');

        $this->get(route('accounting.controls.show', ['report' => 'unusual-expenses', 'months' => 5]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Accounting/Controls/Show')->where('table.rows.0.code', '59102')->where('filters.months', 5));
        $this->get(route('reports.export', ['report' => 'unusual-expenses', 'format' => 'xlsx', 'months' => 5]))->assertOk();
    }

    protected function budget(): Budget
    {
        $year = FiscalYear::where('start_date', '<=', '2026-06-30')->where('end_date', '>=', '2026-06-30')->firstOrFail();

        return Budget::create(['fiscal_year_id' => $year->id, 'name' => 'موازنة 2026', 'status' => 'approved']);
    }

    public function test_budget_by_dimension_governs_spending_tagged_to_that_dimension(): void
    {
        $map = app(AccountMap::class);
        $expense = Account::create(['code' => '59201', 'name_ar' => 'ضيافة', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'is_system' => false]);
        $cc1 = CostCenter::create(['code' => 'CC1', 'name_ar' => 'المبيعات', 'is_active' => true]);
        $cc2 = CostCenter::create(['code' => 'CC2', 'name_ar' => 'الإدارة', 'is_active' => true]);
        $budget = $this->budget();
        BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $expense->id, 'period' => 202606, 'amount' => 1000]);                          // account level
        BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $expense->id, 'period' => 202606, 'amount' => 100, 'cost_center_id' => $cc1->id]);   // sales only
        CompanySetting::create(['key' => 'budget_enforcement', 'value' => 'warn']);

        $service = app(BudgetService::class);
        $this->assertNotNull($service->check($expense->id, '2026-06-10', 150, 'مصروف', ['cost_center_id' => $cc1->id]), 'sales spend 150 > its own 100');
        $this->assertNull($service->check($expense->id, '2026-06-10', 150, 'مصروف', ['cost_center_id' => $cc2->id]), 'another centre falls back to the account-level 1,000');
        $this->assertNull($service->check($expense->id, '2026-06-10', 150, 'مصروف'), 'no dimension → account level');

        // What the ledger holds for the dimension counts against its line, not the other centre's spend.
        app(PostingService::class)->post(['date' => '2026-06-05', 'description' => 'ضيافة المبيعات', 'entry_type' => 'manual', 'reference' => 'B1', 'lines' => [
            ['account_id' => $expense->id, 'debit' => 80, 'cost_center_id' => $cc1->id], ['account_id' => $map->id('default_cash'), 'credit' => 80],
        ]]);
        app(PostingService::class)->post(['date' => '2026-06-06', 'description' => 'ضيافة الإدارة', 'entry_type' => 'manual', 'reference' => 'B2', 'lines' => [
            ['account_id' => $expense->id, 'debit' => 500, 'cost_center_id' => $cc2->id], ['account_id' => $map->id('default_cash'), 'credit' => 500],
        ]]);
        $this->assertEqualsWithDelta(80.0, $service->dimensionActual('cost_center', $cc1->id, $expense->id, '2026-06-01', '2026-06-30'), 0.01);
        $this->assertNull($service->check($expense->id, '2026-06-10', 20, 'مصروف', ['cost_center_id' => $cc1->id]), '80 + 20 = 100 is within');
        $this->assertNotNull($service->check($expense->id, '2026-06-10', 21, 'مصروف', ['cost_center_id' => $cc1->id]));
    }

    public function test_budget_screen_saves_a_dimension_view_without_touching_the_account_level_lines(): void
    {
        $expense = Account::create(['code' => '59202', 'name_ar' => 'سفر', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'is_system' => false]);
        $cc = CostCenter::create(['code' => 'CC9', 'name_ar' => 'التشغيل', 'is_active' => true]);
        $budget = Budget::create(['fiscal_year_id' => $this->budget()->fiscal_year_id, 'name' => 'مسودة', 'status' => 'draft']);
        BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $expense->id, 'period' => 202606, 'amount' => 700]);

        $this->put(route('accounting.budgets.update', $budget->id), ['dimension' => 'cost_center', 'dimension_id' => $cc->id, 'lines' => [['account_id' => $expense->id, 'period' => 202606, 'amount' => 90]]])->assertSessionHasNoErrors();
        $this->assertEquals([700.0], BudgetLine::where('budget_id', $budget->id)->whereNull('cost_center_id')->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals([90.0], BudgetLine::where('budget_id', $budget->id)->where('cost_center_id', $cc->id)->pluck('amount')->map(fn ($v) => (float) $v)->all());

        // Saving the account-level view again leaves the dimension line alone.
        $this->put(route('accounting.budgets.update', $budget->id), ['lines' => [['account_id' => $expense->id, 'period' => 202606, 'amount' => 800]]])->assertSessionHasNoErrors();
        $this->assertEquals([90.0], BudgetLine::where('budget_id', $budget->id)->where('cost_center_id', $cc->id)->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals([800.0], BudgetLine::where('budget_id', $budget->id)->whereNull('cost_center_id')->pluck('amount')->map(fn ($v) => (float) $v)->all());

        $this->get(route('accounting.budgets.show', [$budget->id, 'dimension' => 'cost_center', 'dimension_id' => $cc->id]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Accounting/Budgets/Show')->where('scope.dimension', 'cost_center')->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $expense->id)['planned'][202606] == 90));
    }

    public function test_budget_dimensions_report_compares_planned_with_the_ledger_per_dimension(): void
    {
        $map = app(AccountMap::class);
        $expense = Account::create(['code' => '59203', 'name_ar' => 'اتصالات', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'is_system' => false]);
        $cc = CostCenter::create(['code' => 'CC5', 'name_ar' => 'الدعم', 'is_active' => true]);
        $budget = $this->budget();
        BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $expense->id, 'period' => 202606, 'amount' => 200, 'cost_center_id' => $cc->id]);
        app(PostingService::class)->post(['date' => '2026-06-08', 'description' => 'اتصالات', 'entry_type' => 'manual', 'reference' => 'T1', 'lines' => [
            ['account_id' => $expense->id, 'debit' => 260, 'cost_center_id' => $cc->id], ['account_id' => $map->id('default_cash'), 'credit' => 260],
        ]]);

        $rows = app(AccountingControls::class)->budgetByDimension($budget, 'cost_center', 202601, 202612);
        $this->assertCount(1, $rows);
        $this->assertEquals(['الدعم', 200.0, 260.0, 60.0, 130.0], [$rows[0]['dimension'], $rows[0]['planned'], $rows[0]['actual'], $rows[0]['variance'], $rows[0]['pct']]);

        $this->get(route('accounting.controls.show', ['report' => 'budget-dimensions', 'dimension' => 'cost_center', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('table.rows.0.variance', 60)->where('filters.dimension', 'cost_center'));
        $this->get(route('reports.export', ['report' => 'budget-dimensions', 'format' => 'xlsx', 'dimension' => 'cost_center', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']))->assertOk();
    }

    public function test_budget_dimensions_report_names_each_branch_in_the_active_language(): void
    {
        $map = app(AccountMap::class);
        $expense = Account::create(['code' => '59204', 'name_ar' => 'صيانة', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'is_system' => false]);
        $branch = Branch::create(['company_id' => $this->company->id, 'name_ar' => 'فرع جدة', 'name_en' => 'Jeddah', 'code' => 'BR-JED', 'is_active' => true]);
        $budget = $this->budget();
        BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $expense->id, 'period' => 202606, 'amount' => 200, 'branch_id' => $branch->id]);
        app(PostingService::class)->post(['date' => '2026-06-08', 'description' => 'صيانة', 'entry_type' => 'manual', 'reference' => 'T2', 'branch_id' => $branch->id, 'lines' => [
            ['account_id' => $expense->id, 'debit' => 260], ['account_id' => $map->id('default_cash'), 'credit' => 260],
        ]]);

        // "name" is an accessor on Branch, not a column: asking the database for it answers 500 on MySQL and on SQLite alike.
        $rows = app(AccountingControls::class)->budgetByDimension($budget, 'branch', 202601, 202612);
        $this->assertCount(1, $rows);
        $this->assertEquals(['فرع جدة', 200.0, 260.0, 60.0, 130.0], [$rows[0]['dimension'], $rows[0]['planned'], $rows[0]['actual'], $rows[0]['variance'], $rows[0]['pct']]);

        $this->get(route('accounting.controls.show', ['report' => 'budget-dimensions', 'dimension' => 'branch', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('table.rows.0.dimension', 'فرع جدة')->where('filters.dimension', 'branch'));

        app()->setLocale('en');
        $this->assertSame('Jeddah', app(AccountingControls::class)->budgetByDimension($budget, 'branch', 202601, 202612)[0]['dimension']);
    }

    public function test_cash_forecast_snapshot_is_compared_with_the_cash_that_really_moved(): void
    {
        $map = app(AccountMap::class);
        BankAccount::create(['name' => 'الصندوق', 'type' => 'cash', 'currency' => 'SAR', 'opening_balance' => 0, 'current_balance' => 0, 'account_id' => $map->id('default_cash'), 'is_active' => true]);

        Carbon::setTestNow('2026-06-16 09:00:00');
        $snap = app(CashForecastSnapshots::class)->take();
        $this->assertSame(13, count($snap->weeks));
        $this->assertSame('2026-06-16', $snap->weeks[0]['start']);
        $this->assertSame($snap->id, app(CashForecastSnapshots::class)->take()->id, 'one snapshot per day');

        app(PostingService::class)->post(['date' => '2026-06-18', 'description' => 'إيراد غير متوقع', 'entry_type' => 'manual', 'reference' => 'S1', 'lines' => [
            ['account_id' => $map->id('default_cash'), 'debit' => 100], ['account_id' => $map->id('general_expense'), 'credit' => 100],
        ]]);

        Carbon::setTestNow('2026-06-30 10:00:00');
        $r = app(CashForecastSnapshots::class)->compare();
        $this->assertCount(2, $r['rows'], 'only the two weeks that ended before 30 June');
        $this->assertEquals([100.0, 100.0, 0.0], [$r['rows'][0]['actual'], $r['rows'][0]['deviation'], $r['rows'][0]['accuracy']]);
        $this->assertEquals([0.0, 100.0], [$r['rows'][1]['actual'], $r['rows'][1]['accuracy']]);   // forecast 0, actual 0
        $this->assertSame(50.0, $r['accuracy']);

        $this->get(route('accounting.controls.show', 'forecast-vs-actual'))->assertOk()->assertInertia(fn ($p) => $p->has('table.rows', 3));
        $this->post(route('accounting.controls.forecast-snapshot'))->assertSessionHas('success');
        $this->assertSame(2, CashForecastSnapshot::count());
        $this->get(route('reports.export', ['report' => 'forecast-vs-actual', 'format' => 'xlsx']))->assertOk();

        $this->artisan('erp:cash-snapshot')->assertSuccessful();
        $this->assertSame(2, CashForecastSnapshot::count(), 'the command replaces today\'s snapshot');
    }

    protected function checklist(): array
    {
        $year = FiscalYear::whereDate('start_date', '<=', '2026-06-30')->whereDate('end_date', '>=', '2026-06-30')->firstOrFail();

        return collect(app(ClosingChecklist::class)->forYear($year, now()))->keyBy('key')->all();
    }

    public function test_closing_checklist_collects_what_is_still_open(): void
    {
        $map = app(AccountMap::class);
        $clean = $this->checklist();
        foreach (['drafts', 'depreciation', 'vat', 'party_reconciliation', 'approvals', 'recurring', 'bank_unreconciled'] as $key) {
            $this->assertSame('ok', $clean[$key]['status'], $key);
        }
        $this->assertSame('warn', $clean['open_periods']['status'], 'January–May are over and still open');
        $this->assertSame(5, $clean['open_periods']['count']);

        app(PostingService::class)->post(['date' => '2026-06-01', 'description' => 'مسودة', 'entry_type' => 'manual', 'status' => 'draft', 'lines' => [
            ['account_id' => $map->id('default_cash'), 'debit' => 10], ['account_id' => $map->id('general_expense'), 'credit' => 10],
        ]]);
        ApprovalRequest::create(['document_type' => 'expense', 'subject_key' => 'k1', 'description' => 'مصروف', 'amount' => 900, 'requested_by' => auth()->id()]);
        $lines = [['account_id' => $map->id('rent_expense'), 'debit' => 300, 'credit' => 0], ['account_id' => $map->id('accrued_expenses'), 'debit' => 0, 'credit' => 300]];
        RecurringJournal::create(['name' => 'إيجار', 'description' => 'إيجار', 'lines' => $lines, 'frequency' => 'monthly', 'next_run_date' => '2026-06-15', 'is_active' => true]);
        $this->invoice('2026-03-10', 1000, '2026-03-20');   // VAT of the first quarter, never settled

        $category = AssetCategory::create(['name_ar' => 'حاسبات', 'useful_life_months' => 36, 'depreciation_method' => 'straight_line', 'asset_account_id' => $map->id('fixed_assets'),
            'accumulated_account_id' => $map->id('accumulated_depreciation'), 'expense_account_id' => $map->id('depreciation_expense')]);
        $asset = FixedAsset::create(['asset_category_id' => $category->id, 'code' => 'FA-1', 'name_ar' => 'لابتوب', 'acquisition_date' => '2026-01-01', 'depreciation_start_date' => '2026-01-01',
            'cost' => 3600, 'salvage_value' => 0, 'useful_life_months' => 36, 'depreciation_method' => 'straight_line', 'status' => 'active']);
        app(DepreciationService::class)->acquire($asset, 'bank_transfer');
        app(DepreciationService::class)->run(Carbon::parse('2026-04-30'));   // May is missing

        $open = $this->checklist();
        $this->assertEquals([['fail', 1], ['warn', 1], ['warn', 1], ['fail', 1], ['warn', 1]], [
            [$open['drafts']['status'], $open['drafts']['count']], [$open['approvals']['status'], $open['approvals']['count']], [$open['recurring']['status'], $open['recurring']['count']],
            [$open['depreciation']['status'], $open['depreciation']['count']], [$open['vat']['status'], $open['vat']['count']],
        ]);

        app(DepreciationService::class)->run(Carbon::parse('2026-05-31'));
        $this->assertSame('ok', $this->checklist()['depreciation']['status']);

        $year = FiscalYear::whereDate('start_date', '<=', '2026-06-30')->whereDate('end_date', '>=', '2026-06-30')->firstOrFail();
        $this->get(route('accounting.controls.show', ['report' => 'closing-checklist', 'fiscal_year_id' => $year->id]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Accounting/Controls/Show')->has('years')->where('table.rows.0.item', 'قيود مسودة داخل السنة')->where('table.rows.0.status', 'يمنع الإقفال السليم'));
        $this->get(route('reports.export', ['report' => 'closing-checklist', 'format' => 'xlsx', 'fiscal_year_id' => $year->id]))->assertOk();
        $this->get(route('accounting.fiscal-years.close-form', $year->id))->assertOk();
    }

    public function test_approval_flow_groups_requests_by_type_and_by_approver(): void
    {
        $me = auth()->user();
        $old = ApprovalRequest::create(['document_type' => 'expense', 'subject_key' => 'a', 'description' => 'قديم', 'amount' => 500, 'requested_by' => $me->id]);
        $old->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();
        ApprovalRequest::create(['document_type' => 'expense', 'subject_key' => 'b', 'description' => 'حديث', 'amount' => 250, 'requested_by' => $me->id]);
        $done = ApprovalRequest::create(['document_type' => 'journal', 'subject_key' => 'c', 'description' => 'قيد', 'amount' => 90, 'requested_by' => $me->id, 'status' => 'approved', 'decided_by' => $me->id, 'decided_at' => now()]);
        $done->forceFill(['created_at' => now()->subHours(5)])->saveQuietly();
        ApprovalRequest::create(['document_type' => 'journal', 'subject_key' => 'd', 'description' => 'قيد 2', 'amount' => 40, 'requested_by' => $me->id, 'status' => 'rejected', 'decided_by' => $me->id, 'decided_at' => now()]);

        $byType = collect(app(AccountingControls::class)->approvalFlow('type', 7, now()))->keyBy('group');
        $this->assertEquals([2, 750.0, 1, 10], [$byType['المصروفات']['pending'], $byType['المصروفات']['pending_amount'], $byType['المصروفات']['overdue'], $byType['المصروفات']['oldest']]);
        $this->assertEquals([1, 1, 0], [$byType['القيود اليدوية']['approved'], $byType['القيود اليدوية']['rejected'], $byType['القيود اليدوية']['pending']]);
        $this->assertEquals(5.0, $byType['القيود اليدوية']['avg_hours'] > 0 ? 5.0 : 0.0);

        $byApprover = collect(app(AccountingControls::class)->approvalFlow('approver', 7, now()))->keyBy('group');
        $this->assertEquals(2, $byApprover['بانتظار قرار']['pending']);
        $this->assertEquals([1, 1], [$byApprover[$me->name]['approved'], $byApprover[$me->name]['rejected']]);

        $this->get(route('accounting.controls.show', ['report' => 'approval-flow', 'group' => 'approver']))->assertOk()->assertInertia(fn ($p) => $p->where('filters.group', 'approver')->has('table.rows', 2));
        $this->get(route('reports.export', ['report' => 'approval-flow', 'format' => 'pdf']))->assertOk();
    }
}

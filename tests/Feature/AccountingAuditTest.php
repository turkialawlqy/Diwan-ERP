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
}

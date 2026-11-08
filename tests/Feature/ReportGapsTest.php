<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Gaps found checking the reports against the review: source documents, journal filters, statement period, export audit. */
class ReportGapsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected Invoice $invoice;

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
        $this->invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10', 'due_date' => '2026-06-20'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function manualEntry(): JournalEntry
    {
        $map = app(AccountMap::class);

        return app(PostingService::class)->post(['date' => '2026-06-12', 'description' => 'قيد يدوي', 'entry_type' => 'manual', 'lines' => [
            ['account_id' => $map->id('default_cash'), 'debit' => 50],
            ['account_id' => $map->id('general_expense'), 'credit' => 50],
        ]]);
    }

    public function test_the_ledger_and_the_entry_point_to_the_document_they_came_from(): void
    {
        $receivable = app(AccountMap::class)->id('receivables');
        $this->get(route('accounting.ledger.index', ['account_id' => $receivable, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30']))
            ->assertOk()->assertInertia(fn ($p) => $p->where('ledger.lines.data.0.source.label', 'فاتورة مبيعات')
            ->where('ledger.lines.data.0.source.url', route('sales.invoices.show', $this->invoice->id)));

        $entry = JournalEntry::where('source_id', $this->invoice->id)->first();
        $this->get(route('accounting.journals.show', $entry))->assertInertia(fn ($p) => $p->where('source.url', route('sales.invoices.show', $this->invoice->id)));

        $manual = $this->manualEntry();
        $this->get(route('accounting.journals.show', $manual))->assertInertia(fn ($p) => $p->where('source', null));
    }

    public function test_the_ledger_exports_with_the_source_column_and_a_creation_stamp(): void
    {
        $receivable = app(AccountMap::class)->id('receivables');
        $content = $this->get(route('reports.export', ['report' => 'ledger', 'format' => 'xlsx', 'account_id' => $receivable, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30']))->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'led').'.xlsx';
        file_put_contents($path, $content);
        $book = IOFactory::load($path);
        $rows = $book->getActiveSheet()->toArray(null, true, false, false);
        $description = $book->getProperties()->getDescription();
        unlink($path);

        $flat = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('فاتورة مبيعات', $flat);
        $this->assertStringContainsString('أُنشئ', $description);   // creation time rides in the workbook properties
        $this->assertStringContainsString('2026-06-30 10:00', $description);
        $this->assertEquals(1150, collect($rows)->last()[6]);   // closing balance

        $this->actingAs($this->userWithRole($this->company, 'hr_manager'));
        $this->get(route('reports.export', ['report' => 'ledger', 'format' => 'xlsx', 'account_id' => $receivable]))->assertForbidden();
    }

    public function test_every_export_is_recorded_in_the_audit_log(): void
    {
        $this->get(route('reports.export', ['report' => 'trial-balance', 'format' => 'xlsx']))->assertOk();

        $log = AuditLog::where('event', 'report.exported')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(auth()->id(), (int) $log->user_id);
        $this->assertStringContainsString('trial-balance', json_encode($log->new_values));
    }

    public function test_the_journal_list_filters_by_status_kind_creator_and_source(): void
    {
        $manual = $this->manualEntry();
        $manual->update(['created_by' => auth()->id()]);

        $this->get(route('accounting.journals.index', ['source' => 'none']))->assertInertia(fn ($p) => $p->has('entries.data', 1)->where('entries.data.0.id', $manual->id)
            ->where('creators.0.id', auth()->id())->has('sourceTypes', 1)->where('sourceTypes.0.label', 'فاتورة مبيعات')->has('stats.by_type'));
        $this->get(route('accounting.journals.index', ['source' => Invoice::class]))->assertInertia(fn ($p) => $p->has('entries.data', 1)->where('entries.data.0.source.label', 'فاتورة مبيعات'));
        $this->get(route('accounting.journals.index', ['created_by' => auth()->id(), 'status' => 'posted', 'entry_type' => 'manual']))->assertInertia(fn ($p) => $p->has('entries.data', 1));
        $this->get(route('accounting.journals.index', ['status' => 'draft']))->assertInertia(fn ($p) => $p->has('entries.data', 0));
    }

    public function test_the_customer_statement_takes_a_period_and_lists_open_invoices(): void
    {
        $this->get(route('sales.clients.statement', [$this->customer, 'start_date' => '2026-06-15', 'end_date' => '2026-06-30']))
            ->assertOk()->assertInertia(fn ($p) => $p->where('openingBalance', 1150)->where('filters.start_date', '2026-06-15')
            ->has('openInvoices', 1)->where('openInvoices.0.invoice_number', $this->invoice->invoice_number));
        $this->get(route('sales.clients.statement', [$this->customer]))->assertInertia(fn ($p) => $p->where('openingBalance', 0));
    }

    public function test_the_balance_sheet_splits_current_from_non_current_and_gives_the_current_ratio(): void
    {
        $map = app(AccountMap::class);
        // Cash (current asset) funded by a short loan: 200 current assets against a payable of 100.
        app(PostingService::class)->post(['date' => '2026-06-15', 'description' => 'تمويل', 'entry_type' => 'manual', 'lines' => [
            ['account_id' => $map->id('default_cash'), 'debit' => 200],
            ['account_id' => $map->id('payables'), 'credit' => 100],
            ['account_id' => $map->id('opening_balance_equity'), 'credit' => 100],
        ]]);

        $r = app(AccountingReports::class)->balanceSheet(Carbon::parse('2026-06-30'));
        $this->assertEquals(1350, $r['liquidity']['assets']['current']);        // receivable 1150 + cash 200
        $this->assertEquals(0, $r['liquidity']['assets']['non_current']);
        $this->assertEquals(100 + 150, $r['liquidity']['liabilities']['current']);   // payables + output VAT
        $this->assertEquals(round(1350 / 250, 2), $r['liquidity']['current_ratio']);
        $this->get(route('accounting.reports.balance-sheet', ['as_of' => '2026-06-30']))->assertInertia(fn ($p) => $p->has('report.liquidity.assets.current'));
    }

    public function test_the_income_statement_can_be_set_against_the_approved_budget(): void
    {
        $map = app(AccountMap::class);
        $sales = $map->id('sales_revenue');
        $year = FiscalYear::first() ?? FiscalYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $budget = Budget::create(['fiscal_year_id' => $year->id, 'name' => 'موازنة 2026', 'status' => 'approved']);
        BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $sales, 'period' => 202606, 'amount' => 1500]);
        BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $sales, 'period' => 202607, 'amount' => 9999]);   // outside the period

        $this->get(route('accounting.reports.income-statement', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'compare' => 'budget']))
            ->assertOk()->assertInertia(function ($p) {
                $p->where('comparison.label', 'الموازنة المعتمدة');
                $row = collect($p->toArray()['props']['comparison']['rows'])->firstWhere('name', 'إجمالي الإيرادات');
                $this->assertEquals([1000, 1500, -500], [$row['current'], $row['previous'], $row['change']]);
            });

        $rows = $this->get(route('reports.export', ['report' => 'income-statement', 'format' => 'xlsx', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'compare' => 'budget']))->assertOk();
        $this->assertNotEmpty($rows->getContent());
    }
}

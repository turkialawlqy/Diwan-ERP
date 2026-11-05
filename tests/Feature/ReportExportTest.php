<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Excel / PDF downloads of the financial reports agree with the report pages. */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-10', 'due_date' => '2026-06-10'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /** @return array<int, array<int, mixed>> the sheet as rows */
    protected function sheet(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($path, $content);
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        unlink($path);

        return $rows;
    }

    public function test_the_income_statement_downloads_as_excel_with_the_same_numbers(): void
    {
        $response = $this->get(route('reports.export', ['report' => 'income-statement', 'format' => 'xlsx', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30']))->assertOk();
        $rows = $this->sheet($response->getContent());

        $this->assertSame(['الرمز', 'الحساب', 'المبلغ'], $rows[0]);
        $total = collect($rows)->firstWhere(1, 'إجمالي الإيرادات');
        $this->assertEquals(1000, $total[2]);
        $this->assertEquals(1000, collect($rows)->firstWhere(1, 'صافي الربح')[2]);
    }

    public function test_every_report_downloads_as_excel_and_pdf(): void
    {
        foreach (['income-statement', 'balance-sheet', 'cash-flow', 'trial-balance', 'aging', 'vat-return'] as $report) {
            $this->assertNotEmpty($this->sheet($this->get(route('reports.export', ['report' => $report, 'format' => 'xlsx']))->assertOk()->getContent()), $report);
            $pdf = $this->get(route('reports.export', ['report' => $report, 'format' => 'pdf']))->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->getContent(), $report);
        }
    }

    public function test_a_user_without_the_export_permission_is_refused(): void
    {
        $this->get(route('reports.export', ['report' => 'nonsense', 'format' => 'xlsx']))->assertNotFound();

        $this->actingAs($this->userWithRole($this->company, 'sales'));
        $this->get(route('reports.export', ['report' => 'income-statement', 'format' => 'xlsx']))->assertForbidden();
    }

    public function test_comparison_puts_the_previous_period_beside_the_current_one(): void
    {
        $customer = Customer::first();
        app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => '2026-05-10', 'due_date' => '2026-05-10'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 600, 'tax_rate' => 15]], ['issue_stock' => false]);

        $page = $this->get(route('accounting.reports.income-statement', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'compare' => 'previous']))->assertOk()->viewData('page')['props'];
        $total = collect($page['comparison']['rows'])->firstWhere('name', 'إجمالي الإيرادات');
        $this->assertSame([1000.0, 600.0, 400.0, 66.7], [$total['current'], $total['previous'], $total['change'], $total['pct']]);

        $sheet = $this->sheet($this->get(route('reports.export', ['report' => 'income-statement', 'format' => 'xlsx', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'compare' => 'previous']))->assertOk()->getContent());
        $this->assertEquals(600, collect($sheet)->firstWhere(1, 'إجمالي الإيرادات')[3]);

        $yearAgo = $this->get(route('accounting.reports.income-statement', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'compare' => 'year']))->assertOk()->viewData('page')['props'];
        $this->assertSame(0.0, (float) collect($yearAgo['comparison']['rows'])->firstWhere('name', 'إجمالي الإيرادات')['previous']);

        $this->get(route('accounting.reports.balance-sheet', ['as_of' => '2026-06-30', 'compare' => 'previous']))->assertOk()->assertInertia(fn ($p) => $p->has('comparison.rows'));
    }

    public function test_sales_and_purchase_breakdowns_add_up_net_of_returns_and_export(): void
    {
        $other = Customer::create(['name' => 'عميل ثانٍ', 'customer_type' => 'commercial', 'status' => 'active']);
        app(InvoiceService::class)->create(['customer_id' => $other->id, 'invoice_date' => '2026-06-12', 'due_date' => '2026-06-12'],
            [['item_name' => 'منتج', 'quantity' => 2, 'unit_price' => 250, 'tax_rate' => 15]], ['issue_stock' => false]);

        $props = $this->get(route('reports.sales', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'by' => 'customer']))->assertOk()->viewData('page')['props'];
        $this->assertSame(1500.0, $props['breakdown']['total']['amount']);
        $this->assertSame(['عميل', 'عميل ثانٍ'], collect($props['breakdown']['rows'])->pluck('name')->sort()->values()->all());
        $this->assertSame(66.7, collect($props['breakdown']['rows'])->firstWhere('name', 'عميل')['share']);

        $items = $this->get(route('reports.sales', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'by' => 'item']))->viewData('page')['props']['breakdown'];
        $this->assertSame(2.0, collect($items['rows'])->firstWhere('name', 'منتج')['quantity']);

        $sheet = $this->sheet($this->get(route('reports.export', ['report' => 'sales-analysis', 'format' => 'xlsx', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'by' => 'customer']))->assertOk()->getContent());
        $this->assertEquals(1500, collect($sheet)->firstWhere(0, 'الإجمالي')[3]);

        $this->get(route('reports.purchases', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30']))->assertOk()->assertInertia(fn ($p) => $p->component('Reports/Purchases')->has('breakdown.rows'));
        $this->get(route('reports.export', ['report' => 'purchases-analysis', 'format' => 'pdf']))->assertOk();
    }
}

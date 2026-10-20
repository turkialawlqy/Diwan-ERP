<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Indirect cash flow, fixed asset register and schedule, impairment and
 * units-of-production depreciation.
 */
class CashFlowAndAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function entry(string $date, array $lines): void
    {
        app(PostingService::class)->post(['date' => $date, 'description' => 'اختبار', 'lines' => $lines]);
    }

    protected function role(string $role): int
    {
        return app(AccountMap::class)->id($role, $this->company->id);
    }

    protected function month(): void
    {
        $this->entry('2026-06-02', [['role' => 'receivables', 'debit' => 1150], ['role' => 'sales_revenue', 'credit' => 1000], ['role' => 'vat_output', 'credit' => 150]]);
        $this->entry('2026-06-05', [['role' => 'default_bank', 'debit' => 600], ['role' => 'receivables', 'credit' => 600]]);
        $this->entry('2026-06-06', [['role' => 'inventory', 'debit' => 500], ['role' => 'payables', 'credit' => 500]]);
        $this->entry('2026-06-07', [['role' => 'payables', 'debit' => 200], ['role' => 'default_bank', 'credit' => 200]]);
        $this->entry('2026-06-08', [['role' => 'general_expense', 'debit' => 100], ['role' => 'default_cash', 'credit' => 100]]);
        $this->entry('2026-06-10', [['role' => 'fixed_assets', 'debit' => 3000], ['role' => 'default_bank', 'credit' => 3000]]);
        $this->entry('2026-06-30', [['role' => 'depreciation_expense', 'debit' => 50], ['role' => 'accumulated_depreciation', 'credit' => 50]]);
        $this->entry('2026-06-15', [['role' => 'default_bank', 'debit' => 5000], ['role' => 'long_term_loans', 'credit' => 5000]]);
    }

    // ------------------------------------------------------------------ T1

    public function test_indirect_cash_flow_reconciles_to_the_direct_method(): void
    {
        $this->month();
        $report = app(AccountingReports::class)->cashFlowIndirect(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        $indirect = $report['indirect'];

        $this->assertSame(850.0, $indirect['net_profit']);                  // 1000 - 100 - 50
        $this->assertSame(50.0, collect($indirect['adjustments'])->firstWhere('name', 'الإهلاك')['amount']);
        $wc = collect($indirect['working_capital'])->pluck('amount', 'id');
        $this->assertSame(-550.0, $wc[$this->role('receivables')]);
        $this->assertSame(-500.0, $wc[$this->role('inventory')]);
        $this->assertSame(300.0, $wc[$this->role('payables')]);
        $this->assertSame(150.0, $wc[$this->role('vat_output')]);

        // 600 collected - 200 paid - 100 expense
        $this->assertSame(300.0, $report['sections']['operating']['total']);
        $this->assertSame(300.0, $indirect['operating_total']);
        $this->assertSame(0.0, $indirect['non_cash']);
        $this->assertSame(-3000.0, $report['sections']['investing']['total']);
        $this->assertSame(5000.0, $report['sections']['financing']['total']);
        $this->assertSame(2300.0, $report['net_change']);
    }

    public function test_a_non_cash_asset_purchase_is_shown_on_its_own_line(): void
    {
        $this->month();
        $this->entry('2026-06-20', [['role' => 'fixed_assets', 'debit' => 1000], ['role' => 'payables', 'credit' => 1000]]);

        $indirect = app(AccountingReports::class)->cashFlowIndirect(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'))['indirect'];
        $this->assertSame(300.0, $indirect['operating_total']);
        $this->assertSame(-1000.0, $indirect['non_cash']);
    }

    public function test_cash_flow_page_switches_method(): void
    {
        $this->month();
        $props = $this->get(route('accounting.reports.cash-flow', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'method' => 'indirect']))
            ->assertOk()->viewData('page')['props'];
        $this->assertSame('indirect', $props['filters']['method']);
        $this->assertSame(300.0, $props['report']['indirect']['operating_total']);
        $this->assertArrayNotHasKey('indirect', $this->get(route('accounting.reports.cash-flow'))->viewData('page')['props']['report']);
    }
}

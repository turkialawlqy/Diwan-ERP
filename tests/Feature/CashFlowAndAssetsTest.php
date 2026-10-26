<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\AssetDepreciation;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\EquipmentReading;
use App\Models\FixedAsset;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\FixedAssetRegister;
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

    // ------------------------------------------------------------------ assets

    protected function category(string $method = 'straight_line', int $months = 60): AssetCategory
    {
        return AssetCategory::create(['name_ar' => 'معدات', 'useful_life_months' => $months, 'depreciation_method' => $method,
            'asset_account_id' => $this->role('fixed_assets'), 'accumulated_account_id' => $this->role('accumulated_depreciation'), 'expense_account_id' => $this->role('depreciation_expense')]);
    }

    protected function asset(AssetCategory $category, array $attributes = []): FixedAsset
    {
        $this->post(route('accounting.fixed-assets.store'), array_merge([
            'asset_category_id' => $category->id, 'code' => 'FA-'.random_int(1000, 9999), 'name_ar' => 'أصل', 'acquisition_date' => '2026-01-01',
            'cost' => 12000, 'salvage_value' => 0, 'paid_from' => 'bank_transfer',
        ], $attributes))->assertSessionHasNoErrors();

        return FixedAsset::latest('id')->first();
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find($this->role($role))->balance, 2);
    }

    public function test_schedule_runs_to_the_end_of_life(): void
    {
        $asset = $this->asset($this->category());
        app(DepreciationService::class)->run(Carbon::parse('2026-03-31'));
        $schedule = app(DepreciationService::class)->schedule($asset->fresh());

        $this->assertCount(57, $schedule);                     // 60 months less 3 posted
        $this->assertSame(202604, $schedule[0]['period']);
        $this->assertSame(203012, end($schedule)['period']);
        $this->assertSame(0.0, end($schedule)['book_value']);
        $this->assertEqualsWithDelta(12000 - 600, array_sum(array_column($schedule, 'amount')), 0.01);
    }

    public function test_register_moves_and_matches_the_ledger(): void
    {
        $category = $this->category();
        $old = $this->asset($category, ['code' => 'FA-OLD', 'acquisition_date' => '2025-01-01', 'cost' => 6000]);
        $new = $this->asset($category, ['code' => 'FA-NEW', 'acquisition_date' => '2026-03-15', 'cost' => 12000]);
        $service = app(DepreciationService::class);
        $service->run(Carbon::parse('2025-12-31'));
        $service->run(Carbon::parse('2026-05-31'));
        $service->dispose($old->fresh(), Carbon::parse('2026-06-30'), 3000, null, 'bank_transfer');

        $report = app(FixedAssetRegister::class)->build(Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30'));
        $t = $report['totals'];
        $this->assertSame(6000.0, $t['cost_open']);
        $this->assertSame(12000.0, $t['additions']);
        $this->assertSame(6000.0, $t['disposals']);
        $this->assertSame(12000.0, $t['cost_close']);
        $this->assertSame(1200.0, $t['acc_open']);             // 12 months x 100
        // FA-OLD Jan-Jun 600, FA-NEW Mar-May 600
        $this->assertSame(1200.0, $t['depreciation']);
        $this->assertSame(1800.0, $t['acc_disposed']);
        $this->assertSame(600.0, $t['acc_close']);
        $this->assertSame(11400.0, $t['book_value']);
        $this->assertSame(0.0, $report['ledger']['cost_difference']);
        $this->assertSame(0.0, $report['ledger']['accumulated_difference']);

        $this->get(route('accounting.fixed-assets.register', ['start_date' => '2026-01-01', 'end_date' => '2026-06-30']))->assertOk();
        $this->assertStringContainsString('FA-NEW', $this->get(route('accounting.fixed-assets.register', ['export' => 'csv']))->streamedContent());
    }

    public function test_impairment_writes_down_and_reschedules_depreciation(): void
    {
        $asset = $this->asset($this->category());            // 12000 over 60 months = 200
        $service = app(DepreciationService::class);

        // Depreciation catches up to May, then 2000 of impairment on a book value of 11000.
        $this->post(route('accounting.fixed-assets.impair', $asset), ['date' => '2026-06-10', 'amount' => 2000, 'reason' => 'تلف جزئي'])->assertSessionHasNoErrors();
        $asset->refresh();
        $this->assertSame(1000.0, (float) $asset->accumulated_depreciation);
        $this->assertSame(2000.0, (float) $asset->accumulated_impairment);
        $this->assertSame(9000.0, (float) $asset->book_value);
        $this->assertSame(2000.0, $this->balance('impairment_loss'));

        // 9000 over the 55 months left from June.
        $this->assertSame(round(9000 / 55, 2), $service->monthlyAmount($asset, 202606));

        $this->expectException(AccountingException::class);
        $service->impair($asset, Carbon::parse('2026-06-20'), 9500);
    }

    public function test_disposal_after_impairment_books_the_right_gain(): void
    {
        $asset = $this->asset($this->category());
        $service = app(DepreciationService::class);
        $service->impair($asset, Carbon::parse('2026-06-01'), 2000);  // book value 12000 - 1000 - 2000 = 9000
        $service->dispose($asset->fresh(), Carbon::parse('2026-06-01'), 9500, null, 'bank_transfer');

        $this->assertSame(0.0, $this->balance('fixed_assets'));
        $this->assertSame(0.0, $this->balance('accumulated_depreciation'));
        // June's first day is depreciated before the sale, at the post-impairment rate (9000 over 55 months).
        $this->assertEqualsWithDelta(500 + round(round(9000 / 55, 2) / 30, 2), $this->balance('asset_disposal_gain'), 0.01);
    }

    public function test_units_of_production_follow_usage_and_equipment_hours(): void
    {
        $category = $this->category('units_of_production');
        $this->post(route('accounting.fixed-assets.store'), ['asset_category_id' => $category->id, 'code' => 'FA-X', 'name_ar' => 'حفار', 'acquisition_date' => '2026-01-01',
            'cost' => 600000, 'paid_from' => 'bank_transfer'])->assertSessionHasErrors('total_units');
        $asset = $this->asset($category, ['cost' => 600000, 'total_units' => 6000, 'unit_label' => 'ساعة']);

        $this->post(route('accounting.fixed-assets.usage', $asset), ['month' => '2026-01', 'units' => 120])->assertSessionHasNoErrors();
        // February: no usage; March: hours from the linked equipment.
        $equipment = Equipment::create(['code' => 'EX-1', 'name' => 'حفار', 'meter_type' => 'hours', 'current_hours' => 0, 'fixed_asset_id' => $asset->id, 'status' => 'available']);
        EquipmentReading::create(['equipment_id' => $equipment->id, 'date' => '2026-03-10', 'hours_reading' => 50, 'worked_hours' => 50]);
        EquipmentReading::create(['equipment_id' => $equipment->id, 'date' => '2026-03-20', 'hours_reading' => 80, 'worked_hours' => 30]);

        app(DepreciationService::class)->run(Carbon::parse('2026-03-31'));
        $posted = AssetDepreciation::where('fixed_asset_id', $asset->id)->pluck('amount', 'period')->map(fn ($v) => (float) $v)->all();
        $this->assertSame([202601 => 12000.0, 202603 => 8000.0], $posted);   // 120 h and 80 h at 100 per hour
        $this->assertSame([], app(DepreciationService::class)->schedule($asset->fresh()));
    }
}

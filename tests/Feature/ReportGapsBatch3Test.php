<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\Accounting\AccountingControls;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
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
}

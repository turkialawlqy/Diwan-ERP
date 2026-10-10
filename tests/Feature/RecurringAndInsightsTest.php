<?php

namespace Tests\Feature;

use App\Mail\DocumentMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\RecurringInvoice;
use App\Models\Supplier;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Reports\Insights;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\RecurringInvoiceRunner;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecurringAndInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'مستأجر', 'customer_type' => 'commercial', 'status' => 'active', 'email' => 'tenant@example.test', 'payment_terms_days' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function contract(array $data = []): RecurringInvoice
    {
        $this->post(route('sales.recurring.store'), array_merge([
            'name' => 'إيجار مكتب', 'customer_id' => $this->customer->id, 'frequency' => 'monthly', 'start_date' => '2026-04-01',
            'lines' => [['item_name' => 'إيجار شهري', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ], $data))->assertSessionHasNoErrors();

        return RecurringInvoice::latest('id')->firstOrFail();
    }

    public function test_due_periods_are_issued_once_with_catch_up(): void
    {
        $contract = $this->contract();

        $this->assertSame(3, app(RecurringInvoiceRunner::class)->runDue()); // April, May, June
        $this->assertSame(0, app(RecurringInvoiceRunner::class)->runDue(), 'running again issues nothing');

        $invoices = Invoice::orderBy('invoice_date')->get();
        $this->assertSame(['2026-04-01', '2026-05-01', '2026-06-01'], $invoices->map(fn ($i) => $i->invoice_date->toDateString())->all());
        $this->assertEquals(1150.0, (float) $invoices->first()->total);
        $this->assertSame('2026-04-11', $invoices->first()->due_date->toDateString(), 'customer payment terms');
        $this->assertSame('2026-07-01', $contract->fresh()->next_run_date->toDateString());
        $this->assertSame(3, $contract->fresh()->generated_count);
    }

    public function test_max_count_ends_the_contract_and_auto_send_emails(): void
    {
        Mail::fake();
        $contract = $this->contract(['max_count' => 2, 'auto_send' => true]);

        app(RecurringInvoiceRunner::class)->runDue();

        $this->assertSame(2, Invoice::count());
        $this->assertSame('ended', $contract->fresh()->status);
        Mail::assertSent(DocumentMail::class, 2);
        Mail::assertSent(DocumentMail::class, fn ($m) => $m->hasTo('tenant@example.test'));
    }

    public function test_month_end_start_dates_clamp_and_paused_contracts_wait(): void
    {
        $contract = $this->contract(['start_date' => '2026-01-31']);
        $contract->update(['status' => 'paused']);
        $this->assertSame(0, app(RecurringInvoiceRunner::class)->runDue());

        $contract->update(['status' => 'active']);
        app(RecurringInvoiceRunner::class)->runDue();
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'], Invoice::orderBy('invoice_date')->get()->map(fn ($i) => $i->invoice_date->toDateString())->all());
    }

    public function test_stock_items_are_refused_and_page_prefills_from_invoice(): void
    {
        $stock = Product::create(['name_ar' => 'جهاز', 'sku' => 'S-1', 'selling_price' => 10, 'purchase_price' => 5, 'track_inventory' => true, 'is_active' => true, 'type' => 'product']);
        $this->post(route('sales.recurring.store'), [
            'name' => 'خطأ', 'customer_id' => $this->customer->id, 'frequency' => 'monthly', 'start_date' => '2026-07-01',
            'lines' => [['product_id' => $stock->id, 'item_name' => 'جهاز', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertSessionHasErrors('lines');

        $invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15'], [['item_name' => 'صيانة', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 15]], ['issue_stock' => false]);
        $this->get(route('sales.recurring.index', ['from_invoice' => $invoice->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Sales/Recurring/Index')->where('prefill.customer_id', $this->customer->id)->where('prefill.lines.0.item_name', 'صيانة'));
    }

    public function test_cash_forecast_places_flows_by_due_date(): void
    {
        $this->contract(['start_date' => '2026-07-01']); // future: forecast only
        app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'due_date' => '2026-06-25', 'payment_status' => 'unpaid', 'payment_method' => 'credit'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 2000, 'tax_rate' => 15]], ['issue_stock' => false]);
        app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-05-01', 'due_date' => '2026-05-10', 'payment_status' => 'unpaid', 'payment_method' => 'credit'],
            [['item_name' => 'متأخرة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15]], ['issue_stock' => false]);
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        PurchaseInvoice::create(['supplier_id' => $supplier->id, 'invoice_number' => 'B-1', 'invoice_date' => '2026-06-01', 'due_date' => '2026-07-02', 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'subtotal' => 1000, 'tax_amount' => 150, 'total' => 1150, 'paid_amount' => 0, 'remaining_amount' => 1150]);

        $cash = app(Insights::class)->cashForecast();

        $this->assertEqualsWithDelta(2300.0, $cash['weeks'][1]['receivables'], 0.01, 'due 25 June → week 2');
        $this->assertEqualsWithDelta(115.0, $cash['overdue']['receivables'], 0.01);
        $this->assertEqualsWithDelta(1150.0, $cash['weeks'][2]['payables'], 0.01, 'due 2 July → week 3');
        $this->assertEqualsWithDelta(1150.0, $cash['weeks'][3]['recurring'], 0.01, 'July rent due 11 July → week 4');
        $this->assertEqualsWithDelta($cash['opening'] + 2300 + 1150 * 3 - 1150, $cash['closing'], 0.01);
    }

    public function test_insights_page_renders_every_tab(): void
    {
        app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15'], [['item_name' => 'خدمة', 'quantity' => 2, 'unit_price' => 300, 'tax_rate' => 15]], ['issue_stock' => false]);

        $this->get(route('reports.insights'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reports/Insights')->has('cash.weeks', 13)->has('aging.receivables.totals')->has('profitability.customers', 1)->where('profitability.customers.0.revenue', 600));
    }
}

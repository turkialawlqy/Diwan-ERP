<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Invoice;
use App\Models\ServicePlan;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Sales\RecurringInvoiceRunner;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Customer subscriptions: plans, billing through the recurring-invoice template, and the lifecycle. */
class SubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'شركة العميل', 'customer_type' => 'commercial', 'status' => 'active', 'email' => 'c@example.test', 'payment_terms_days' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function plan(array $extra = []): ServicePlan
    {
        $this->post(route('sales.service-plans.store'), array_merge(['name' => 'الباقة الأساسية', 'price' => 300, 'tax_rate' => 15, 'cycle' => 'monthly', 'trial_days' => 0], $extra))->assertSessionHasNoErrors();

        return ServicePlan::latest('id')->firstOrFail();
    }

    protected function subscribe(ServicePlan $plan, array $extra = []): CustomerSubscription
    {
        $this->post(route('sales.subscriptions.store'), array_merge(['customer_id' => $this->customer->id, 'service_plan_id' => $plan->id, 'quantity' => 1, 'start_date' => '2026-06-15'], $extra))->assertSessionHasNoErrors();

        return CustomerSubscription::latest('id')->firstOrFail();
    }

    protected function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    public function test_a_plan_gets_a_code_and_a_subscription_bills_its_first_period_at_once(): void
    {
        $plan = $this->plan();
        $this->assertSame('PLN-0001', $plan->code);

        $sub = $this->subscribe($plan);

        $this->assertSame('SBS-00001', $sub->number);
        $this->assertSame('active', $sub->status);
        $invoice = Invoice::firstOrFail();
        $this->assertEquals(345.0, (float) $invoice->total); // 300 + 15%
        $this->assertSame($this->customer->id, $invoice->customer_id);
        $this->assertSame('2026-07-15', $sub->recurring->fresh()->next_run_date->toDateString());
        $this->assertSame('2026-07-15', $this->service()->renewsOn($sub->fresh())->toDateString());
        $this->assertEquals(300.0, $this->service()->mrr());
    }

    public function test_a_trial_bills_nothing_until_it_ends(): void
    {
        $sub = $this->subscribe($this->plan(['trial_days' => 14]));

        $this->assertSame('trial', $sub->status);
        $this->assertSame(0, Invoice::count());
        $this->assertSame('2026-06-29', $sub->trial_ends_at->toDateString());

        Carbon::setTestNow('2026-06-29 01:00:00');
        $done = $this->service()->nightly();
        app(RecurringInvoiceRunner::class)->runDue();

        $this->assertSame(1, $done['trial_ended']);
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame(1, Invoice::count());
    }

    public function test_pausing_stops_billing_and_resuming_does_not_back_bill(): void
    {
        $sub = $this->subscribe($this->plan());
        $this->post(route('sales.subscriptions.pause', $sub->id))->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-09-20 01:00:00');
        app(RecurringInvoiceRunner::class)->runDue();
        $this->assertSame(1, Invoice::count(), 'nothing while paused');
        $this->assertSame('paused', $sub->fresh()->status);

        // (Later than the test company's SaaS trial, so the HTTP layer would be read-only: use the service.)
        $this->service()->resume($sub->fresh());
        $this->assertSame('2026-09-20', $sub->fresh()->recurring->next_run_date->toDateString());
        app(RecurringInvoiceRunner::class)->runDue();
        $this->assertSame(2, Invoice::count(), 'one period, not three');
    }

    public function test_cancelling_at_period_end_keeps_it_until_the_renewal_then_ends_without_another_invoice(): void
    {
        $sub = $this->subscribe($this->plan());
        $this->post(route('sales.subscriptions.cancel', $sub->id), ['reason' => 'انتقل لمنافس', 'at_period_end' => true])->assertSessionHasNoErrors();
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertTrue($sub->fresh()->cancel_at_period_end);

        // The cancellation can be withdrawn.
        $this->post(route('sales.subscriptions.keep', $sub->id));
        $this->assertFalse($sub->fresh()->cancel_at_period_end);
        $this->post(route('sales.subscriptions.cancel', $sub->id), ['reason' => 'x', 'at_period_end' => true]);

        Carbon::setTestNow('2026-07-15 01:00:00');
        $done = $this->service()->nightly();
        app(RecurringInvoiceRunner::class)->runDue();

        $this->assertSame(1, $done['ended']);
        $this->assertSame('cancelled', $sub->fresh()->status);
        $this->assertSame('ended', $sub->recurring->fresh()->status);
        $this->assertSame(1, Invoice::count());
    }

    public function test_an_upgrade_bills_the_prorata_difference_and_a_downgrade_waits_for_the_renewal(): void
    {
        $basic = $this->plan(['price' => 300]);
        $pro = $this->plan(['name' => 'الباقة المتقدمة', 'price' => 600]);
        $sub = $this->subscribe($basic); // paid 15 Jun -> 15 Jul (30 days)

        Carbon::setTestNow('2026-06-30 10:00:00'); // 15 days left
        $this->post(route('sales.subscriptions.change-plan', $sub->id), ['service_plan_id' => $pro->id, 'when' => 'now'])->assertSessionHasNoErrors();

        $sub->refresh();
        $this->assertSame($pro->id, $sub->service_plan_id);
        $this->assertEquals(600.0, (float) $sub->unit_price);
        $diff = Invoice::orderByDesc('id')->first();
        $this->assertEquals(172.5, (float) $diff->total); // (600-300)/30 x 15 = 150 + 15%
        $this->assertSame(2, Invoice::count());

        // Back to the cheaper plan: only at the renewal.
        $this->post(route('sales.subscriptions.change-plan', $sub->id), ['service_plan_id' => $basic->id, 'when' => 'now'])->assertSessionHasNoErrors();
        $this->assertSame($pro->id, $sub->fresh()->service_plan_id);
        $this->assertSame($basic->id, $sub->fresh()->pending_plan_id);
        $this->assertSame(2, Invoice::count());

        Carbon::setTestNow('2026-07-15 01:00:00');
        $this->assertSame(1, $this->service()->nightly()['plan_changed']);
        app(RecurringInvoiceRunner::class)->runDue();
        $this->assertSame($basic->id, $sub->fresh()->service_plan_id);
        $this->assertEquals(345.0, (float) Invoice::orderByDesc('id')->first()->total);
    }

    public function test_an_unpaid_invoice_makes_it_past_due_and_paying_recovers_it(): void
    {
        $sub = $this->subscribe($this->plan());
        $invoice = Invoice::firstOrFail(); // due 25 Jun

        Carbon::setTestNow('2026-07-01 01:00:00'); // due 25 Jun + 7 days of grace = 2 Jul
        $this->assertSame(0, $this->service()->nightly()['past_due']);

        Carbon::setTestNow('2026-07-10 01:00:00');
        $this->assertSame(1, $this->service()->nightly()['past_due']);
        $this->assertSame('past_due', $sub->fresh()->status);

        $invoice->forceFill(['payment_status' => 'paid', 'paid_amount' => $invoice->total, 'remaining_amount' => 0])->save();
        $this->assertSame(1, $this->service()->nightly()['recovered']);
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_renewal_reminders_go_out_once_per_step(): void
    {
        $sub = $this->subscribe($this->plan()); // renews 15 Jul
        $paid = Invoice::firstOrFail();
        $paid->forceFill(['payment_status' => 'paid', 'paid_amount' => $paid->total, 'remaining_amount' => 0])->save(); // so only the reminders notify

        Carbon::setTestNow('2026-07-08 01:00:00');
        $this->assertSame(1, $this->service()->nightly()['reminders']);
        $this->assertSame(0, $this->service()->nightly()['reminders'], 'the same step is not sent twice');
        $this->assertSame(1, $this->app['db']->table('notifications')->count());

        Carbon::setTestNow('2026-07-12 01:00:00');
        $this->assertSame(1, $this->service()->nightly()['reminders']); // 3 days
        $this->assertSame($sub->id, CustomerSubscription::firstOrFail()->id);
    }

    public function test_a_subscription_with_invoices_cannot_be_deleted_and_a_plan_in_use_cannot_either(): void
    {
        $plan = $this->plan();
        $sub = $this->subscribe($plan);

        $this->delete(route('sales.subscriptions.destroy', $sub->id))->assertSessionHasErrors('subscription');
        $this->delete(route('sales.service-plans.destroy', $plan->id))->assertSessionHasErrors('plan');
        $this->assertDatabaseHas('customer_subscriptions', ['id' => $sub->id]);
    }

    public function test_the_pages_open_and_need_their_permission(): void
    {
        $sub = $this->subscribe($this->plan());

        $this->get(route('sales.subscriptions.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Sales/Subscriptions/Index')->where('stats.live', 1));
        $this->get(route('sales.subscriptions.show', $sub->id))->assertOk()->assertInertia(fn ($p) => $p->component('Sales/Subscriptions/Show')->has('invoices', 1));
        $this->get(route('sales.subscriptions.create'))->assertOk();
        $this->get(route('sales.service-plans.index'))->assertOk();

        $this->actingAs($this->userWithRole($this->company, 'warehouse_keeper'));
        $this->get(route('sales.subscriptions.index'))->assertForbidden();
        $this->post(route('sales.subscriptions.pause', $sub->id))->assertForbidden();
    }
}

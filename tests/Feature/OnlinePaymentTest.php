<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Saas\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Moyasar checkout + webhook — exercised with faked HTTP only (never against the live service). */
class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected SubscriptionInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create(['subdomain' => 'alpha']);
        app(AccountingSetupService::class)->provision($this->company);
        $plan = SubscriptionPlan::create(['name_ar' => 'أساسية', 'name_en' => 'Basic', 'slug' => 'basic', 'price_monthly' => 100, 'price_yearly' => 1000,
            'max_users' => -1, 'max_branches' => -1, 'max_warehouses' => -1, 'max_employees' => -1, 'max_invoices_per_month' => -1, 'is_active' => true]);
        $this->actingAs($this->userWithRole($this->company));
        $this->invoice = app(SubscriptionService::class)->request($this->company, $plan, 'monthly');
    }

    protected function enable(): void
    {
        config(['saas.payments.moyasar' => ['enabled' => true, 'secret_key' => 'sk_test_x', 'webhook_token' => 'whsec', 'base_url' => 'https://api.moyasar.com/v1']]);
    }

    protected function paymentResponse(array $override = []): array
    {
        return array_merge(['id' => 'pay_1', 'status' => 'paid', 'amount' => (int) round($this->invoice->total * 100), 'currency' => 'SAR', 'metadata' => ['subscription_invoice_id' => (string) $this->invoice->id]], $override);
    }

    public function test_it_is_closed_by_default(): void
    {
        Http::fake();
        $this->postJson(route('billing.invoices.pay', $this->invoice))->assertStatus(422)->assertJsonValidationErrors('payment');
        $this->postJson(route('webhooks.moyasar'), ['secret_token' => 'x', 'data' => ['id' => 'pay_1']])->assertStatus(400);
        Http::assertNothingSent();
        $this->get(route('billing.index'))->assertInertia(fn ($p) => $p->where('paymentsEnabled', false));
    }

    public function test_checkout_creates_a_provider_invoice_in_halalas_for_own_unpaid_invoices_only(): void
    {
        $this->enable();
        Http::fake(['api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_1', 'url' => 'https://checkout.moyasar.com/invoices/inv_1'], 201)]);

        $this->postJson(route('billing.invoices.pay', $this->invoice))->assertOk()->assertJson(['url' => 'https://checkout.moyasar.com/invoices/inv_1']);
        Http::assertSent(fn ($r) => $r['amount'] === (int) round($this->invoice->total * 100) && $r['currency'] === 'SAR' && $r['metadata']['subscription_invoice_id'] === (string) $this->invoice->id);

        $other = SubscriptionInvoice::create(array_merge($this->invoice->only(['subscription_plan_id', 'billing_cycle', 'period_start', 'period_end', 'amount', 'vat_amount', 'total']), ['company_id' => Company::factory()->create()->id, 'number' => 'SUB-X-1', 'status' => 'unpaid', 'issued_at' => now()]));
        $this->postJson(route('billing.invoices.pay', $other))->assertNotFound();

        $this->invoice->update(['status' => 'void']);
        $this->postJson(route('billing.invoices.pay', $this->invoice))->assertStatus(422);
    }

    public function test_a_verified_webhook_settles_the_invoice_once_without_csrf(): void
    {
        $this->enable();
        Http::fake(['api.moyasar.com/v1/payments/pay_1' => Http::response($this->paymentResponse())]);
        $this->post(route('webhooks.moyasar'), ['secret_token' => 'whsec', 'type' => 'payment_paid', 'data' => ['id' => 'pay_1']], ['Accept' => 'application/json'])->assertOk();   // no CSRF token sent

        $this->invoice->refresh();
        $this->assertSame(['paid', 'moyasar', 'pay_1'], [$this->invoice->status, $this->invoice->payment_method, $this->invoice->payment_reference]);
        $this->assertSame('active', $this->company->fresh()->status);

        $this->postJson(route('webhooks.moyasar'), ['secret_token' => 'whsec', 'data' => ['id' => 'pay_1']])->assertOk();   // repeated delivery is acknowledged
        $this->assertSame('pay_1', $this->invoice->fresh()->payment_reference);
    }

    public function test_forged_or_mismatching_webhooks_change_nothing(): void
    {
        $this->enable();
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::sequence()
            ->push($this->paymentResponse(['amount' => 100]))                // wrong amount
            ->push($this->paymentResponse(['status' => 'failed']))           // not paid
            ->push($this->paymentResponse(['metadata' => ['subscription_invoice_id' => '999999']])), // unknown invoice
        ]);

        $this->postJson(route('webhooks.moyasar'), ['secret_token' => 'wrong', 'data' => ['id' => 'pay_1']])->assertStatus(400);   // bad token: nothing is even read back
        Http::assertNothingSent();
        foreach (range(1, 3) as $i) {
            $this->postJson(route('webhooks.moyasar'), ['secret_token' => 'whsec', 'data' => ['id' => 'pay_1']])->assertStatus(400);
        }
        $this->postJson(route('webhooks.moyasar'), ['secret_token' => 'whsec', 'data' => ['id' => '../etc']])->assertStatus(400);
        $this->assertSame('unpaid', $this->invoice->fresh()->status);
    }
}

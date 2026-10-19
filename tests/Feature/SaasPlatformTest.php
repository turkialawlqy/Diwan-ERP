<?php

namespace Tests\Feature;

use App\Jobs\ExportCompanyData;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DataExport;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Saas\AlertService;
use App\Services\Saas\DomainVerifier;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SaasPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['subdomain' => 'alpha']);
        app(AccountingSetupService::class)->provision($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
    }

    protected function plan(array $attributes = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'name_ar' => 'أساسية', 'name_en' => 'Basic', 'slug' => 'basic-'.uniqid(), 'price_monthly' => 100, 'price_yearly' => 1000,
            'max_users' => -1, 'max_branches' => -1, 'max_warehouses' => -1, 'max_employees' => -1, 'max_invoices_per_month' => -1, 'is_active' => true,
        ], $attributes));
    }

    public function test_modules_outside_the_plan_are_refused(): void
    {
        $this->company->update(['subscription_plan_id' => $this->plan(['modules' => ['sales', 'accounting']])->id]);

        $this->get(route('sales.invoices.index'))->assertOk();
        $this->get(route('hr.employees.index'))->assertForbidden();
        $this->get(route('projects.index'))->assertForbidden();
    }

    public function test_plan_limits_block_creation(): void
    {
        $this->company->update(['subscription_plan_id' => $this->plan(['max_users' => 1])->id]);

        $this->post(route('users.store'), ['name' => 'X', 'email' => 'x@example.com', 'password' => 'Secret#12345', 'password_confirmation' => 'Secret#12345', 'role' => 'sales', 'status' => 'active'])
            ->assertSessionHasErrors('plan');
        $this->assertSame(1, User::where('company_id', $this->company->id)->count());
    }

    public function test_expired_subscription_is_read_only_except_billing(): void
    {
        $plan = $this->plan();
        $this->company->update(['status' => 'active', 'subscription_plan_id' => $plan->id, 'subscription_ends_at' => now()->subDays(30)]);

        $this->get(route('sales.clients.index'))->assertOk();
        $this->post(route('sales.clients.store'), ['name' => 'عميل', 'customer_type' => 'individual'])->assertStatus(402);
        $this->post(route('billing.plan'), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])->assertSessionHasNoErrors();

        $invoice = SubscriptionInvoice::firstOrFail();
        $this->assertSame(115.0, (float) $invoice->total);

        // Platform admin confirms the payment → subscription active again.
        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null, 'status' => 'active']);
        app(TenantManager::class)->forget();
        $this->actingAs($admin)->post(route('super-admin.invoices.confirm', $invoice), ['payment_reference' => 'TRX-1'])->assertSessionHasNoErrors();

        $this->assertTrue($this->company->fresh()->subscription_ends_at->isFuture());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertTrue(PlatformAuditLog::where('action', 'subscription_activated')->exists());
    }

    public function test_api_token_is_scoped_to_company_and_abilities(): void
    {
        $other = Company::factory()->create(['subdomain' => 'beta']);
        $theirs = app(TenantManager::class)->runAs($other, fn () => Customer::create(['name' => 'عميل منافس', 'customer_type' => 'commercial', 'status' => 'active']));
        $mine = app(TenantManager::class)->runAs($this->company, fn () => Customer::create(['name' => 'عميلي', 'customer_type' => 'commercial', 'status' => 'active']));

        $token = $this->owner->createToken('erp', ['sales.clients.view'])->plainTextToken;
        app(TenantManager::class)->forget();
        auth()->forgetGuards();
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $list = $this->getJson('/api/v1/customers', $headers)->assertOk()->json('data');
        $this->assertSame([$mine->id], array_column($list, 'id'));
        $this->getJson("/api/v1/customers/{$theirs->id}", $headers)->assertNotFound();
        $this->postJson('/api/v1/customers', ['name' => 'جديد'], $headers)->assertForbidden(); // token lacks the ability
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('company.id', $this->company->id);
    }

    public function test_webhook_is_signed_and_delivered_on_invoice(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
        app(TenantManager::class)->setTenant($this->company);
        $this->post(route('webhooks.store'), ['url' => 'https://hooks.example.com/erp', 'events' => ['invoice.created']])->assertSessionHasNoErrors();
        $endpoint = WebhookEndpoint::firstOrFail();

        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => now()->toDateString()],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15]], ['issue_stock' => false]);

        Http::assertSent(function (HttpRequest $request) use ($endpoint) {
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Diwan-Timestamp')[0].'.'.$request->body(), $endpoint->secret);

            return $request->url() === 'https://hooks.example.com/erp'
                && $request->header('X-Diwan-Event')[0] === 'invoice.created'
                && hash_equals($expected, $request->header('X-Diwan-Signature')[0])
                && json_decode($request->body(), true)['data']['total'] === 115;
        });
        $this->assertSame(200, $endpoint->fresh()->last_status);
    }

    public function test_custom_domain_needs_dns_proof(): void
    {
        $verifier = new class extends DomainVerifier
        {
            public array $records = [];

            protected function txtRecords(string $host): array
            {
                return $this->records;
            }
        };
        $verifier->assign($this->company, 'erp.alpha-trading.com');
        $company = $this->company->fresh();
        $this->assertNull($company->custom_domain_verified_at);
        $this->assertFalse($verifier->verify($company));

        $verifier->records = [$company->custom_domain_token];
        $this->assertTrue($verifier->verify($company));
        $this->assertNotNull($company->fresh()->custom_domain_verified_at);
    }

    public function test_impersonation_requires_reason_and_is_audited(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null, 'status' => 'active']);
        $this->actingAs($admin);

        $this->post(route('super-admin.impersonate.enter', $this->company), [])->assertSessionHasErrors('reason');
        // Without a recent password confirmation the admin is asked for it first.
        $this->post(route('super-admin.impersonate.enter', $this->company), ['reason' => 'دعم فني للعميل'])->assertRedirect(route('password.confirm'));
        $this->assertFalse(PlatformAuditLog::where('action', 'impersonation_started')->exists());
        session(['auth.password_confirmed_at' => time()]);
        $this->post(route('super-admin.impersonate.enter', $this->company), ['reason' => 'دعم فني للعميل'])->assertRedirect();
        $this->assertTrue(PlatformAuditLog::where('action', 'impersonation_started')->where('company_id', $this->company->id)->exists());

        $this->get(route('dashboard'))->assertOk();
        // An expired impersonation ends: the admin is sent back to the platform console.
        session(['impersonate_expires_at' => now()->subMinute()->timestamp]);
        $this->get(route('dashboard'))->assertRedirect(route('super-admin.dashboard'));
        $this->assertFalse(session()->has('impersonate_company_id'));
    }

    public function test_crm_lead_lifecycle(): void
    {
        $this->post(route('crm.leads.store'), ['name' => 'سعد', 'company_name' => 'مؤسسة سعد', 'expected_value' => 50000])->assertRedirect();
        $lead = Lead::firstOrFail();
        $this->post(route('crm.leads.activities.store', $lead), ['type' => 'call', 'notes' => 'مهتم بعرض سعر', 'next_follow_up' => now()->addDays(2)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('contacted', $lead->fresh()->status);

        $this->post(route('crm.leads.convert', $lead))->assertRedirect();
        $this->assertSame('won', $lead->fresh()->status);
        $this->assertSame('مؤسسة سعد', Customer::find($lead->fresh()->customer_id)->company_name);
        $this->get(route('crm.leads.index'))->assertOk();
        $this->get(route('crm.leads.show', $lead))->assertOk();
    }

    public function test_customer_import_validates_all_rows_then_imports(): void
    {
        $bad = UploadedFile::fake()->createWithContent('c.csv', "name,tax_number,email\nأحمد,123,ahmad@example.com\n");
        $this->post(route('data-transfer.import', 'customers'), ['file' => $bad])->assertSessionHasErrors('file');
        $this->assertSame(0, Customer::count());

        $good = UploadedFile::fake()->createWithContent('c.csv', "name,tax_number,city\nأحمد,310122393500003,الرياض\nسارة,,جدة\n");
        $this->post(route('data-transfer.import', 'customers'), ['file' => $good])->assertSessionHasNoErrors();
        $this->assertSame(2, Customer::count());
        $this->assertNotNull(Customer::where('name', 'سارة')->value('code'));

        $export = $this->get(route('data-transfer.export', 'customers'));
        $export->assertOk();
        $this->assertStringContainsString('أحمد', $export->streamedContent());
        $this->get(route('data-transfer.index'))->assertOk();
    }

    public function test_alerts_reach_users_with_permission(): void
    {
        app(TenantManager::class)->setTenant($this->company);
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        Invoice::create(['customer_id' => $customer->id, 'invoice_number' => 'INV-1', 'invoice_date' => now()->subDays(40), 'due_date' => now()->subDays(10),
            'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'remaining_amount' => 115, 'payment_status' => 'unpaid']);
        $cashier = $this->userWithRole($this->company, 'cashier');

        app(AlertService::class)->run($this->company);

        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'overdue_invoices')->count());
        $this->assertSame(0, $cashier->notifications()->count());
        $this->get(route('notifications.index'))->assertOk();
    }

    public function test_company_data_export_excludes_secrets(): void
    {
        $export = DataExport::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'status' => 'queued']);
        (new ExportCompanyData($export->id, $this->company->id))->handle(app(TenantManager::class));

        $export->refresh();
        $this->assertSame('ready', $export->status);
        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($export->path));
        $users = $zip->getFromName('users.csv');
        $this->assertStringContainsString($this->owner->email, $users);
        $this->assertStringNotContainsString('password', strtolower(strtok($users, "\n")));
        $this->assertNotFalse($zip->getFromName('accounts.csv'));
        $zip->close();
    }
}

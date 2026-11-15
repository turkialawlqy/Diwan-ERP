<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommissionPlan;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Regression tests for the internal security review (docs/SECURITY-REVIEW.md). */
class SecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Company $a;

    protected Company $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->a = Company::factory()->create(['subdomain' => 'alpha']);
        $this->b = Company::factory()->create(['subdomain' => 'beta']);
        app(AccountingSetupService::class)->provision($this->a);
        app(AccountingSetupService::class)->provision($this->b);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_commission_plan_cannot_attach_a_user_of_another_company(): void
    {
        app(TenantManager::class)->setTenant($this->a);
        $mine = $this->userWithRole($this->a);
        $foreign = $this->userWithRole($this->b);
        $this->actingAs($mine);

        $payload = ['name' => 'خطة', 'type' => 'flat', 'rate' => 2, 'is_active' => true];
        $this->post(route('sales.commission-plans.store'), $payload + ['users' => [$foreign->id]])->assertSessionHasErrors('users.0');
        $this->assertSame(0, CommissionPlan::count());
        $this->post(route('sales.commission-plans.store'), $payload + ['users' => [$mine->id]])->assertSessionHasNoErrors();
        $this->assertSame(1, CommissionPlan::count());
    }

    public function test_report_exports_refuse_another_companys_records(): void
    {
        app(TenantManager::class)->setTenant($this->b);
        $foreignSupplier = Supplier::create(['name' => 'مورد شركة أخرى', 'status' => 'active']);
        $foreignAccount = Account::where('type', 'expense')->where('is_parent', false)->firstOrFail();

        app(TenantManager::class)->setTenant($this->a);
        $this->actingAs($this->userWithRole($this->a));
        $this->get(route('reports.export', ['report' => 'supplier-statement', 'format' => 'xlsx', 'supplier_id' => $foreignSupplier->id]))->assertSessionHasErrors('supplier_id');
        $this->get(route('reports.export', ['report' => 'ledger', 'format' => 'xlsx', 'account_id' => $foreignAccount->id]))->assertSessionHasErrors('account_id');
        $this->get(route('reports.export', ['report' => 'project-allocation', 'format' => 'xlsx', 'run_id' => 999999]))->assertSessionHasErrors('run_id');
    }

    public function test_public_routes_need_their_signature_token_or_closed_gateway(): void
    {
        // A share link without its signature is refused; the payment webhook is closed unless the gateway is enabled.
        $this->get(route('share.pdf', ['company' => $this->a->id, 'type' => 'invoice', 'id' => 1]))->assertForbidden();
        $this->get(route('share.statement', ['company' => $this->a->id, 'customer' => 1]))->assertForbidden();
        $this->postJson(route('webhooks.moyasar'), ['id' => 'x', 'secret_token' => 'wrong'])->assertStatus(400);
    }

    public function test_secrets_never_reach_the_browser_in_shared_props_or_settings_pages(): void
    {
        app(TenantManager::class)->setTenant($this->a);
        $owner = $this->userWithRole($this->a);
        CompanySetting::create(['key' => 'mail_password', 'value' => encrypt('s3cret-smtp-pass')]);
        $this->actingAs($owner);

        foreach ([route('dashboard'), route('settings.messaging')] as $url) {
            $body = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('s3cret-smtp-pass', $body, $url);
            $this->assertDoesNotMatchRegularExpression('/"(password|remember_token|two_factor_secret|private_key)"\s*:\s*"[^"]+"/', $body, $url);
        }
    }

    public function test_the_web_root_exposes_no_environment_or_storage_files(): void
    {
        foreach (['.env', '.env.example', 'composer.json', 'artisan', 'storage', 'database', 'vendor'] as $path) {
            $this->assertFileDoesNotExist(public_path($path), "public/{$path} must not exist");
        }
        $this->assertStringContainsString('-Indexes', (string) file_get_contents(public_path('.htaccess')));
    }

    public function test_every_authenticated_route_is_mapped_to_a_permission_or_explicitly_open(): void
    {
        // PermissionMatrixTest proves the map is complete; here: a user with no role cannot reach any ERP page.
        app(TenantManager::class)->setTenant($this->a);
        $nobody = User::factory()->create(['company_id' => $this->a->id, 'status' => 'active']);
        $this->actingAs($nobody);
        foreach (['finance.expenses.index', 'accounting.ledger.index', 'sales.invoices.index', 'hr.payroll.index', 'projects.index', 'finance.payment-requests.index', 'settings.messaging'] as $name) {
            if (Route::has($name)) {
                $this->get(route($name))->assertForbidden();
            }
        }
    }

    public function test_a_manual_entry_cannot_point_at_another_companys_customer_and_read_his_name(): void
    {
        app(TenantManager::class)->setTenant($this->b);
        $foreign = Customer::create(['name' => 'عميل سري لشركة أخرى', 'customer_type' => 'commercial', 'status' => 'active']);

        app(TenantManager::class)->setTenant($this->a);
        $this->actingAs($this->userWithRole($this->a));
        $map = app(AccountMap::class);
        $lines = fn ($party) => [
            ['account_id' => $map->id('receivables'), 'debit' => 100, 'credit' => 0, 'party_type' => 'customer', 'party_id' => $party],
            ['account_id' => $map->id('sales_revenue'), 'debit' => 0, 'credit' => 100],
        ];
        $base = ['date' => '2026-06-01', 'description' => 'قيد', 'entry_type' => 'manual'];

        $this->post(route('accounting.journals.store'), $base + ['lines' => $lines($foreign->id)])->assertSessionHasErrors('lines.0.party_id');
        $this->assertSame(0, JournalEntry::count());

        $mine = Customer::create(['name' => 'عميلي', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->post(route('accounting.journals.store'), $base + ['lines' => $lines($mine->id)])->assertSessionHasNoErrors();
        $entry = JournalEntry::firstOrFail();
        $this->get(route('accounting.journals.show', $entry->id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('parties.customer', fn ($names) => collect($names)->values()->all() === ['عميلي']));
    }
}

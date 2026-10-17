<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\PostingService;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Saas\WebhookDispatcher;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Production readiness: account takeover paths, host header, deactivated
 * users, SSRF, and the data guards needed on day one.
 */
class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        $this->owner = $this->userWithRole($this->company, null, ['password' => 'owner-pass-123']);
        app(TenantManager::class)->setTenant($this->company);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ security

    public function test_forged_host_headers_are_not_served_in_production_mode(): void
    {
        config(['tenancy.reject_unknown_hosts' => true, 'tenancy.central_domain' => 'alostaz.test']);

        $this->post('http://evil.example/forgot-password', ['email' => $this->owner->email])->assertNotFound();
        $this->get('http://alostaz.test/login')->assertOk();
        config(['tenancy.allowed_hosts' => ['erp.lan']]);
        $this->get('http://erp.lan/login')->assertOk();
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        $known = $this->post(route('password.email'), ['email' => $this->owner->email]);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        $unknown->assertSessionHasNoErrors();
        $this->assertSame(session('status'), $known->getSession()->get('status'));
    }

    public function test_a_deactivated_user_loses_access_on_the_next_request_and_their_tokens(): void
    {
        $clerk = $this->userWithRole($this->company, 'sales');
        $clerk->createToken('pos');
        $this->actingAs($clerk)->get(route('dashboard'))->assertOk();

        $this->flushSession();
        $this->actingAs($this->owner)->put(route('users.update', $clerk), ['name' => $clerk->name, 'email' => $clerk->email, 'role' => 'sales', 'status' => 'inactive'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $clerk->tokens()->count());

        $this->flushSession();
        $this->actingAs($clerk->fresh())->get(route('dashboard'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_a_limited_user_manager_cannot_reset_a_more_powerful_users_password(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        Role::create(['name' => 'hr-clerk', 'guard_name' => 'web', 'company_id' => $this->company->id])
            ->givePermissionTo(['admin.users.view', 'admin.users.edit']);
        $clerk = $this->userWithRole($this->company, 'hr-clerk');
        $accountant = $this->userWithRole($this->company, 'accountant');

        $this->actingAs($clerk)->put(route('users.update', $accountant), ['name' => 'x', 'email' => $accountant->email, 'password' => 'Takeover#12345', 'role' => 'accountant', 'status' => 'active'])
            ->assertForbidden();
        $this->assertTrue(\Hash::check('password', $accountant->fresh()->password));
    }

    public function test_changing_the_login_email_needs_the_current_password(): void
    {
        $this->actingAs($this->owner)->patch(route('profile.update'), ['name' => 'x', 'email' => 'new@example.com'])->assertSessionHasErrors('current_password');
        $this->actingAs($this->owner)->patch(route('profile.update'), ['name' => 'x', 'email' => 'new@example.com', 'current_password' => 'owner-pass-123'])->assertSessionHasNoErrors();
        $this->assertSame('new@example.com', $this->owner->fresh()->email);

        // Company accounts cannot delete themselves from the profile.
        $this->delete(route('profile.destroy'), ['password' => 'owner-pass-123'])->assertSessionHasErrors('password');
        $this->assertNotNull($this->owner->fresh());
    }

    public function test_self_registered_accounts_must_verify_their_email(): void
    {
        $user = $this->userWithRole($this->company, 'sales', ['email_verified_at' => null]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_two_factor_lockout_grows_after_repeated_failures(): void
    {
        $user = $this->userWithRole($this->company, 'sales', ['password' => 'secret-pass']);
        $twoFactor = app(TwoFactor::class);
        $twoFactor->begin($user);
        $twoFactor->confirm($user, app(Totp::class)->code($user->fresh()->two_factor_secret));

        $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-pass']);
        foreach (range(1, 5) as $i) {
            $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors(['code' => 'محاولات كثيرة، حاول بعد 15 دقيقة.']);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('event', 'two_factor_lockout')->exists());

        // Restarting enrolment cannot silently switch an active setup off.
        $this->actingAs($user->fresh())->postJson(route('two-factor.enable'), ['current_password' => 'secret-pass'])->assertStatus(422);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_webhooks_cannot_target_internal_addresses(): void
    {
        foreach (['https://[fd00::1]/hook', 'https://[::ffff:127.0.0.1]/hook', 'https://100.64.0.5/hook', 'https://169.254.169.254/latest', 'https://[fe80::1]/x', 'https://0.0.0.0/x'] as $url) {
            try {
                WebhookDispatcher::assertSafeUrl($url);
                $this->fail("Accepted {$url}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('8.8.8.8', WebhookDispatcher::assertSafeUrl('https://8.8.8.8/hook'));
    }

    public function test_salary_view_permission_cannot_change_pay_items(): void
    {
        $routes = config('erp_permissions.routes');
        $this->assertSame(['hr.employees.edit', 'hr.employees.edit'], [$routes['hr.employees.pay-items.store'], $routes['hr.employees.pay-items.destroy']]);
    }

    public function test_a_user_with_activity_is_deactivated_instead_of_deleted(): void
    {
        $cashier = $this->userWithRole($this->company, 'sales');
        app(PostingService::class)->post(['date' => now(), 'description' => 'x', 'created_by' => $cashier->id,
            'lines' => [['role' => 'default_cash', 'debit' => 10], ['role' => 'capital', 'credit' => 10]]]);

        $this->actingAs($this->owner)->delete(route('users.destroy', $cashier))->assertSessionHasNoErrors();
        $this->assertSame('inactive', $cashier->fresh()?->status);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function enrolled(?string $role = null): array
    {
        $user = $this->userWithRole($this->company, $role, ['password' => 'secret-pass']);
        $twoFactor = app(TwoFactor::class);
        $twoFactor->begin($user);
        $codes = $twoFactor->confirm($user, app(Totp::class)->code($user->fresh()->two_factor_secret, time() - 30));

        return [$user->fresh(), $codes];
    }

    public function test_totp_matches_rfc_6238_vectors(): void
    {
        $totp = new Totp;
        $secret = $totp->base32Encode('12345678901234567890');

        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, $totp->code($secret, $time));
        }
        $step = $totp->verify($secret, '287082', null, 1, 59);
        $this->assertNotNull($step);
        $this->assertNull($totp->verify($secret, '287082', $step, 1, 59), 'a used code cannot be replayed');
    }

    public function test_login_requires_the_code_when_enabled(): void
    {
        [$user] = $this->enrolled();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-pass'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('two-factor.verify'), ['code' => app(Totp::class)->code($user->two_factor_secret)])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_never_reaches_the_challenge(): void
    {
        [$user] = $this->enrolled();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_recovery_code_works_once(): void
    {
        [$user, $codes] = $this->enrolled();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-pass']);
        $this->post(route('two-factor.verify'), ['recovery_code' => strtoupper($codes[0])])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-pass']);
        $this->post(route('two-factor.verify'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_challenge_is_rate_limited(): void
    {
        [$user] = $this->enrolled();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-pass']);

        foreach (range(1, 5) as $i) {
            $this->post(route('two-factor.verify'), ['code' => '000000']);
        }
        $this->post(route('two-factor.verify'), ['code' => app(Totp::class)->code($user->two_factor_secret)])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_enrolment_from_profile(): void
    {
        $user = $this->userWithRole($this->company, null, ['password' => 'secret-pass']);
        $this->actingAs($user);

        $this->postJson(route('two-factor.enable'), ['current_password' => 'wrong'])->assertStatus(422);
        $setup = $this->postJson(route('two-factor.enable'), ['current_password' => 'secret-pass'])->assertOk()->json();
        $this->assertStringStartsWith('otpauth://totp/', $setup['uri']);
        $this->assertNotSame(str_replace(' ', '', $setup['secret']), $user->fresh()->getRawOriginal('two_factor_secret'), 'secret is stored encrypted');

        $this->postJson(route('two-factor.confirm'), ['code' => '000000'])->assertStatus(422);
        $codes = $this->postJson(route('two-factor.confirm'), ['code' => app(Totp::class)->code(str_replace(' ', '', $setup['secret']))])->assertOk()->json('recovery_codes');

        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['event' => 'two_factor_enabled', 'user_id' => $user->id]);
    }

    public function test_company_policy_forces_enrolment_and_blocks_disabling(): void
    {
        $accountant = $this->userWithRole($this->company, 'accountant', ['password' => 'secret-pass']);
        $sales = $this->userWithRole($this->company, 'sales');
        app(TwoFactor::class)->setRequiredRoles($this->company, ['accountant']);
        app(TenantManager::class)->setTenant($this->company);

        $this->actingAs($accountant)->get(route('dashboard'))->assertRedirect(route('profile.edit', ['two_factor' => 'required']));
        $this->get(route('profile.edit'))->assertOk();
        $this->flushSession(); // another person's login starts its own session
        $this->actingAs($sales)->get(route('dashboard'))->assertOk();

        [$user] = [$accountant];
        app(TwoFactor::class)->begin($user);
        app(TwoFactor::class)->confirm($user, app(Totp::class)->code($user->fresh()->two_factor_secret));
        $this->flushSession();
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
        $this->deleteJson(route('two-factor.disable'), ['current_password' => 'secret-pass'])->assertStatus(422);
    }

    public function test_platform_admin_must_enrol(): void
    {
        config(['security.two_factor_super_admin' => true]);
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($admin)->get(route('super-admin.dashboard'))->assertRedirect(route('profile.edit', ['two_factor' => 'required']));
    }
}

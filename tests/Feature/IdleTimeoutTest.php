<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_page_gets_the_idle_minutes_and_keep_alive_works(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn ($p) => $p->where('idleMinutes', 30));
        $this->post(route('session.keep-alive'))->assertNoContent();
    }

    public function test_a_user_idle_longer_than_the_setting_is_signed_out(): void
    {
        $this->company->setSetting('session_timeout_minutes', '5');

        $this->withSession(['last_activity_at' => time() - 4 * 60])->get(route('dashboard'))->assertOk();
        $this->withSession(['last_activity_at' => time() - 6 * 60])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_zero_turns_the_timeout_off(): void
    {
        $this->company->setSetting('session_timeout_minutes', '0');

        $this->withSession(['last_activity_at' => time() - 600 * 60])->get(route('dashboard'))->assertOk();
    }

    public function test_pos_screens_are_not_signed_out(): void
    {
        $this->company->setSetting('session_timeout_minutes', '5');

        $this->withSession(['last_activity_at' => time() - 60 * 60])->get(route('pos.history'))->assertOk()->assertInertia(fn ($p) => $p->where('idleMinutes', 0));
        $this->assertAuthenticated();
        $this->withSession(['last_activity_at' => time() - 60 * 60])->get(route('dashboard'))->assertRedirect(route('login'));
    }
}

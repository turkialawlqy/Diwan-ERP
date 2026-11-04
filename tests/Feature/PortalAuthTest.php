<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PortalUser;
use App\Notifications\PortalLoginCode;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Customer portal sign-in: an e-mailed one-time code, no password. */
class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected PortalUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        RateLimiter::clear('x');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $customer = Customer::create(['name' => 'شركة العميل', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->user = PortalUser::create(['customer_id' => $customer->id, 'name' => 'سارة', 'email' => 'sara@example.test', 'status' => 'active']);
        app(TenantManager::class)->forget();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function url(string $name, array $extra = []): string
    {
        return route($name, ['company' => $this->company->id] + $extra);
    }

    /** Ask for a code and read it from the notification that would have been mailed. */
    protected function requestCode(string $email = 'sara@example.test'): ?string
    {
        $this->post($this->url('customer-portal.send-code'), ['email' => $email])->assertRedirect($this->url('customer-portal.verify'));

        $sent = Notification::sent($this->user, PortalLoginCode::class)->last();

        return $sent ? (fn () => $this->code)->call($sent) : null;
    }

    public function test_the_full_code_flow_signs_the_customer_in_and_logout_ends_it(): void
    {
        $this->get($this->url('customer-portal.home'))->assertRedirect($this->url('customer-portal.login'));
        $props = $this->get($this->url('customer-portal.login'))->assertOk()->viewData('page')['props'];
        // No account details of the company for a visitor.
        $this->assertNull($props['tenant']['tax_number'] ?? null);
        $this->assertNull($props['subscription'] ?? null);
        $this->assertSame([], $props['currencies'] ?? []);

        $code = $this->requestCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertDatabaseMissing('portal_login_codes', ['code_hash' => $code]); // stored hashed only

        $this->post($this->url('customer-portal.verify.check'), ['code' => $code])->assertRedirect($this->url('customer-portal.home'));
        $this->assertAuthenticatedAs($this->user->fresh(), 'portal');
        $this->assertNotNull($this->user->fresh()->last_login_at);
        $this->get($this->url('customer-portal.home'))->assertOk()->assertInertia(fn ($p) => $p->component('Portal/Home'));

        $this->post($this->url('customer-portal.logout'));
        $this->assertGuest('portal');
        $this->get($this->url('customer-portal.home'))->assertRedirect($this->url('customer-portal.login'));
    }

    public function test_a_code_is_single_use_and_a_new_code_cancels_the_old_one(): void
    {
        $first = $this->requestCode();
        $second = $this->requestCode();
        $this->assertNotSame($first, $second);

        $this->post($this->url('customer-portal.verify.check'), ['code' => $first])->assertSessionHasErrors('code');
        $this->assertGuest('portal');
        $this->post($this->url('customer-portal.verify.check'), ['code' => $second])->assertRedirect($this->url('customer-portal.home'));

        Auth::guard('portal')->logout();
        $this->post($this->url('customer-portal.verify.check'), ['code' => $second])->assertSessionHasErrors('code');
    }

    public function test_an_expired_code_and_too_many_wrong_tries_are_refused(): void
    {
        $code = $this->requestCode();
        $this->travel(11)->minutes();
        $this->post($this->url('customer-portal.verify.check'), ['code' => $code])->assertSessionHasErrors('code');
        $this->travelBack();

        $code = $this->requestCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->url('customer-portal.verify.check'), ['code' => $wrong])->assertSessionHasErrors('code');
        }
        $this->post($this->url('customer-portal.verify.check'), ['code' => $code])->assertSessionHasErrors('code'); // dead after 5 tries
        $this->assertGuest('portal');
    }

    public function test_an_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        $this->post($this->url('customer-portal.send-code'), ['email' => 'nobody@example.test'])->assertRedirect($this->url('customer-portal.verify'));
        Notification::assertNothingSent();
        $this->post($this->url('customer-portal.verify.check'), ['code' => '123456'])->assertSessionHasErrors('code');
    }

    public function test_sending_codes_is_rate_limited_per_email(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post($this->url('customer-portal.send-code'), ['email' => 'sara@example.test'])->assertRedirect($this->url('customer-portal.verify'));
        }
        $this->post($this->url('customer-portal.send-code'), ['email' => 'sara@example.test'])->assertSessionHasErrors('email');
        Notification::assertSentToTimes($this->user, PortalLoginCode::class, 3);
    }

    public function test_a_disabled_user_gets_no_code_and_loses_a_live_session(): void
    {
        $code = $this->requestCode();
        $this->post($this->url('customer-portal.verify.check'), ['code' => $code]);
        $this->get($this->url('customer-portal.home'))->assertOk();

        $this->user->update(['status' => 'disabled']);
        $this->app['auth']->forgetGuards(); // a real request loads the user afresh
        $this->get($this->url('customer-portal.home'))->assertRedirect($this->url('customer-portal.login'));
        $this->assertGuest('portal');

        Notification::fake();
        $this->post($this->url('customer-portal.send-code'), ['email' => 'sara@example.test']);
        Notification::assertNothingSent();
    }

    public function test_an_idle_session_ends_after_thirty_minutes(): void
    {
        $this->post($this->url('customer-portal.verify.check'), ['code' => $this->requestCode()]);
        $this->get($this->url('customer-portal.home'))->assertOk();
        $this->travel(31)->minutes();
        $this->get($this->url('customer-portal.home'))->assertRedirect($this->url('customer-portal.login'));
    }

    public function test_the_portal_is_unavailable_for_a_suspended_company_or_a_missing_one(): void
    {
        $this->get(route('customer-portal.login', ['company' => 999999]))->assertNotFound();
        $this->company->update(['status' => 'suspended']);
        $this->get($this->url('customer-portal.login'))->assertNotFound();
    }

    public function test_a_code_of_one_company_does_not_sign_in_the_same_email_of_another(): void
    {
        $other = Company::factory()->create();
        $code = $this->requestCode();
        $this->post(route('customer-portal.verify.check', ['company' => $other->id]), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('portal');
    }
}

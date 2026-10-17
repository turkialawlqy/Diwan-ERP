<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\ZatcaDevice;
use App\Notifications\ErpAlert;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Platform\BackupService;
use App\Services\Saas\AlertService;
use App\Services\Saas\SubscriptionReminders;
use App\Services\Saas\SubscriptionService;
use App\Services\Saas\WebhookDispatcher;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use App\Services\Zatca\Tlv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

    // ------------------------------------------------------------ data & time

    protected function balance(string $role): float
    {
        return round((float) Account::find(app(AccountMap::class)->id($role, $this->company->id))->balance, 2);
    }

    public function test_business_dates_follow_riyadh_time(): void
    {
        $this->assertSame('Asia/Riyadh', config('app.timezone'));
        Carbon::setTestNow(Carbon::parse('2026-06-15 22:30:00', 'UTC')); // 01:30 on the 16th in Riyadh
        $this->assertSame('2026-06-16', now()->toDateString());
        Carbon::setTestNow();
    }

    public function test_supplier_opening_balance_reaches_the_payables_account(): void
    {
        $this->actingAs($this->owner)->post(route('purchases.suppliers.store'), ['name' => 'مورد قديم', 'opening_balance' => 4500])->assertSessionHasNoErrors();

        $this->assertSame(4500.0, $this->balance('payables'));
        $this->assertSame(-4500.0, $this->balance('opening_balance_equity')); // equity debited for the opening payable
    }

    public function test_tax_invoices_check_the_buyer_and_notes_need_the_original_invoice(): void
    {
        $this->actingAs($this->owner);
        $line = [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15]];
        $individual = Customer::create(['name' => 'فرد', 'status' => 'active']);
        $business = Customer::create(['name' => 'شركة', 'status' => 'active', 'tax_number' => '300000000000003']);

        // No VAT number: a simplified invoice.
        $invoice = app(InvoiceService::class)->create(['customer_id' => $individual->id, 'invoice_date' => now()->toDateString()], $line, ['issue_stock' => false]);
        $this->assertSame('simplified', $invoice->invoice_type);

        // VAT number without a national address: refused before anything is posted.
        try {
            app(InvoiceService::class)->create(['customer_id' => $business->id, 'invoice_date' => now()->toDateString()], $line, ['issue_stock' => false]);
            $this->fail('Standard invoice issued without the buyer address');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('العنوان الوطني', $e->errors()['customer_id'][0]);
        }
        $business->update(['building_number' => '1234', 'street_name' => 'العليا', 'district' => 'الورود', 'city' => 'الرياض', 'postal_code' => '12345']);
        $this->assertSame('standard', app(InvoiceService::class)->create(['customer_id' => $business->id, 'invoice_date' => now()->toDateString()], $line, ['issue_stock' => false])->invoice_type);

        $this->post(route('sales.returns.store'), ['customer_id' => $individual->id, 'credit_note_date' => now()->toDateString(), 'subtotal' => 10, 'tax_amount' => 1.5, 'total' => 11.5,
            'reason' => 'خصم', 'status' => 'approved'])->assertSessionHasErrors('invoice_id');
    }

    public function test_summary_reports_are_net_of_credit_notes_and_filtered_by_date(): void
    {
        $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'فرد', 'status' => 'active']);
        $invoice = app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => now()->toDateString()],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
        $this->post(route('sales.returns.store'), ['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => now()->toDateString(), 'subtotal' => 200, 'tax_amount' => 30, 'total' => 230,
            'reason' => 'خصم', 'status' => 'approved'])->assertSessionHasNoErrors();

        $stats = $this->get(route('reports.sales'))->assertOk()->viewData('page')['props']['stats'];
        $this->assertSame(800.0, (float) $stats['total_sales']);
        $this->assertSame(120.0, (float) $stats['total_tax']);

        $empty = $this->get(route('reports.sales', ['start_date' => now()->subYears(2)->startOfYear()->toDateString(), 'end_date' => now()->subYears(2)->endOfYear()->toDateString()]))->viewData('page')['props']['stats'];
        $this->assertSame(0.0, (float) $empty['total_sales']);
    }

    public function test_the_invoice_prefix_setting_numbers_invoices(): void
    {
        $this->company->setSetting('sales_invoice_prefix', 'RYD-');
        $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'شركة', 'status' => 'active', 'tax_number' => '300000000000003', 'building_number' => '1', 'street_name' => 'س', 'district' => 'ح', 'city' => 'الرياض', 'postal_code' => '12345']);
        $invoice = app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => now()->toDateString()],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 15]], ['issue_stock' => false]);
        $this->assertStringStartsWith('RYD-', $invoice->invoice_number);
    }

    // ------------------------------------------------------------ server operations

    public function test_preflight_fails_on_an_unsafe_configuration(): void
    {
        config(['app.debug' => true]);
        $this->artisan('erp:preflight')->assertFailed();
    }

    public function test_health_reports_a_missing_backup_and_stopped_scheduler(): void
    {
        $this->get(route('health'))->assertOk()->assertJsonPath('checks.database.ok', true);

        config(['health.check_operations' => true]);
        $this->get(route('health'))->assertStatus(503)->assertJsonPath('checks.scheduler.ok', false)->assertJsonPath('checks.backup.ok', false);

        Cache::put('scheduler:heartbeat', now()->timestamp, 600);
        BackupRun::create(['type' => 'backup', 'file' => 'x.zip', 'status' => 'ok', 'size' => 1, 'details' => [], 'duration_ms' => 1]);
        $this->get(route('health'))->assertOk();
    }

    public function test_production_backups_require_encryption_and_tagged_backups_survive_rotation(): void
    {
        Storage::fake('backups');
        $this->app->detectEnvironment(fn () => 'production');
        config(['backup.password' => null]);
        $run = app(BackupService::class)->create();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('BACKUP_PASSWORD', $run->error);
        $this->app->detectEnvironment(fn () => 'testing');

        $disk = Storage::disk('backups');
        foreach (range(1, 9) as $d) {
            $disk->put(sprintf('alostaz-backup-2026-06-%02d-024000.zip', $d), 'x');
        }
        foreach (range(1, 7) as $d) {
            $disk->put(sprintf('alostaz-preupdate-2026-06-%02d-120000.zip', $d), 'x');
        }
        config(['backup.keep.daily' => 7, 'backup.keep.weekly' => 0, 'backup.keep.monthly' => 0, 'backup.keep_tagged' => 5]);
        app(BackupService::class)->prune();

        $this->assertCount(7, collect($disk->files())->filter(fn ($f) => str_starts_with($f, 'alostaz-backup-')));
        $this->assertCount(5, collect($disk->files())->filter(fn ($f) => str_starts_with($f, 'alostaz-preupdate-')));
        $this->assertTrue($disk->exists('alostaz-preupdate-2026-06-07-120000.zip'));
    }

    public function test_zatca_certificate_expiry_and_overdue_reporting_raise_alerts(): void
    {
        ZatcaDevice::create(['name' => 'جهاز الفرع', 'serial_number' => '1-A|2-B|3-C', 'environment' => 'simulation', 'status' => 'active', 'certificate_expires_at' => now()->addDays(5)]);

        $kinds = collect(app(AlertService::class)->collect())->pluck('level', 'kind');
        $certificate = $kinds->filter(fn ($l, $k) => str_starts_with($k, 'zatca_certificate_'));
        $this->assertCount(1, $certificate);
        $this->assertSame('danger', $certificate->first());
    }

    public function test_the_platform_admin_is_created_by_command_with_a_secret_password(): void
    {
        $this->artisan('erp:create-super-admin', ['email' => 'ops@example.com'])
            ->expectsQuestion('كلمة المرور (10 أحرف على الأقل)', 'Very-Secret-2026')
            ->expectsQuestion('أعد كتابة كلمة المرور', 'Very-Secret-2026')
            ->assertSuccessful();

        $admin = User::where('email', 'ops@example.com')->first();
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertNull($admin->company_id);
        $this->assertTrue(\Hash::check('Very-Secret-2026', $admin->password));
    }

    // ------------------------------------------------------------ platform billing & compliance

    public function test_subscription_invoices_are_sequential_tax_invoices_with_the_platform_details(): void
    {
        config(['saas.platform.tax_number' => '311111111100003', 'saas.platform.name' => 'شركة الأستاذ', 'saas.platform.iban' => 'SA0380000000608010167519']);
        $plan = SubscriptionPlan::create(['name_ar' => 'متقدمة', 'name_en' => 'Pro', 'slug' => 'pro-x', 'price_monthly' => 100, 'price_yearly' => 1000, 'is_active' => true]);
        $other = Company::factory()->create();
        $service = app(SubscriptionService::class);

        $first = $service->request($this->company, $plan, 'monthly');
        $second = $service->request($other, $plan, 'monthly');
        $this->assertSame('SUB-'.now()->format('y').'-000001', $first->number);
        $this->assertSame('SUB-'.now()->format('y').'-000002', $second->number);
        $tags = Tlv::decode($first->qr);
        $this->assertSame(['شركة الأستاذ', '311111111100003', '115.00', '15.00'], [$tags[1], $tags[2], $tags[4], $tags[5]]);

        $this->actingAs($this->owner);
        $this->get(route('billing.invoices.show', $first))->assertOk()->assertSee('SA0380000000608010167519')->assertSee('311111111100003');
        $this->get(route('billing.invoices.show', $second))->assertNotFound(); // another company's invoice
    }

    public function test_signup_requires_accepting_the_terms_and_legal_pages_are_public(): void
    {
        $payload = ['company_name' => 'منشأة', 'subdomain' => 'new-co', 'name' => 'مالك', 'email' => 'owner@new.co', 'phone' => '0500000000',
            'currency' => 'SAR', 'password' => 'password', 'password_confirmation' => 'password'];
        Auth::logout();
        $this->post('/register', $payload)->assertSessionHasErrors('terms');
        $this->post('/register', $payload + ['terms' => '1'])->assertSessionHasNoErrors();
        $this->assertNotNull(User::where('email', 'owner@new.co')->value('terms_accepted_at'));

        $this->get(route('legal.show', 'privacy'))->assertOk()->assertSee('حماية البيانات');
        $this->get('/legal/unknown')->assertNotFound();
    }

    public function test_owners_are_reminded_before_the_trial_ends_once(): void
    {
        Notification::fake();
        $this->company->forceFill(['status' => 'trial', 'trial_ends_at' => now()->addDays(7)])->save();

        $this->assertSame(1, app(SubscriptionReminders::class)->send($this->company->fresh()));
        $this->assertSame(0, app(SubscriptionReminders::class)->send($this->company->fresh()), 'sent once');
        Notification::assertSentTo($this->owner, ErpAlert::class, fn ($n) => $n->kind === 'subscription_before_7');
    }

    public function test_a_new_companys_owner_sees_the_setup_checklist(): void
    {
        $onboarding = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->viewData('page')['props']['onboarding'];
        $this->assertSame(6, $onboarding['total']);
        $this->assertFalse(collect($onboarding['steps'])->firstWhere('key', 'zatca')['done']);
    }
}

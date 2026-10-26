<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountMap;
use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-request caches of account mappings.
        $this->app->scoped(AccountMap::class);

        $this->app->singleton(TenantManager::class, function () {
            return new TenantManager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->arabicAuthMails();

        // Sum of a money column in base currency (documents with exchange_rate).
        Builder::macro('sumBase', function (string $column) {
            /** @var Builder $this */
            return round((float) $this->sum(DB::raw("{$column} * exchange_rate")), 2);
        });

        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();
        $this->registerTenantValidationRules();
        $this->registerSecurityEventLogging();
        $this->registerAuthorization();

        if (config('security.force_https')) {
            URL::forceScheme('https');
        }

        // Production passwords: 10+ characters and not in known breach lists.
        Password::defaults(fn () => $this->app->environment('production')
            ? Password::min(10)->uncompromised()
            : Password::min(8));
    }

    /**
     * The company owner role and an impersonating platform super admin bypass
     * permission checks; everyone else goes through their role permissions.
     */
    protected function registerAuthorization(): void
    {
        Gate::before(function ($user, $ability) {
            $tenantManager = app(TenantManager::class);

            if (! $tenantManager->hasTenant()) {
                return null;
            }

            if ($user->isSuperAdmin() && $tenantManager->isImpersonating()) {
                return true;
            }

            if ((int) $user->company_id === (int) $tenantManager->getTenantId() && $user->isCompanyOwner()) {
                return true;
            }

            return null;
        });
    }

    /**
     * Log sign-ins (successful and failed) to the audit trail.
     */
    protected function registerSecurityEventLogging(): void
    {
        Event::listen(Login::class, function ($event) {
            $user = $event->user;
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
            AuditLog::record('login', null, [], [], $user->company_id, $user->id);
        });

        Event::listen(Failed::class, function ($event) {
            AuditLog::record('login_failed', null, [], [
                'email' => $event->credentials['email'] ?? null,
            ], $event->user?->company_id, $event->user?->id);
        });
    }

    /**
     * "tenant_exists:table[,column]" behaves like "exists" but only matches rows
     * owned by the current tenant, so a request can never reference (and attach
     * itself to) another company's customer, account, product, warehouse, etc.
     * It fails closed when no tenant is resolved.
     */
    protected function registerTenantValidationRules(): void
    {
        Validator::extend('tenant_exists', function ($attribute, $value, $parameters) {
            $tenantId = app(TenantManager::class)->getTenantId();
            $table = $parameters[0] ?? null;
            $column = $parameters[1] ?? 'id';

            if (! $tenantId || ! $table || $value === null || $value === '' || is_array($value)) {
                return false;
            }

            static $softDeletes = [];
            $softDeletes[$table] ??= Schema::hasColumn($table, 'deleted_at');

            return DB::table($table)
                ->where($column, $value)
                ->where('company_id', $tenantId)
                ->when($softDeletes[$table], fn ($q) => $q->whereNull('deleted_at'))
                ->exists();
        }, 'القيمة المحددة غير صالحة.');
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Login: max 5 attempts per minute per IP
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Registration: max 3 per minute per IP
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // Forgot-password requests: max 3 per minute per IP+email, to slow down
        // both mass email-enumeration and mail-bombing a single address.
        RateLimiter::for('password-email', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip().'|'.strtolower((string) $request->input('email')));
        });

        // Password reset submission: max 5 per minute per IP
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // POS order processing: max 30 per minute per authenticated user
        RateLimiter::for('pos-orders', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip());
        });

        // Invoice creation: max 20 per minute per authenticated user
        RateLimiter::for('invoices', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?? $request->ip());
        });

        // Every company route: per user, plus a per-company ceiling so one tenant
        // cannot starve the shared infrastructure for everyone else.
        RateLimiter::for('tenant-web', function (Request $request) {
            $companyId = app(TenantManager::class)->getTenantId() ?? 'none';

            return [
                Limit::perMinute((int) config('tenancy.rate_limit_per_user', 300))->by('user:'.($request->user()?->id ?? $request->ip())),
                Limit::perMinute((int) config('tenancy.rate_limit_per_company', 3000))->by('company:'.$companyId),
            ];
        });

        // General API: max 120 per minute per user/IP
        // Plan limits are enforced on creation, whatever the entry point.
        $limit = function (string $resource) {
            return function ($model) use ($resource) {
                $companyId = $model->company_id ?? app(TenantManager::class)->getTenantId();
                if (! $companyId || ($resource === 'users' && $model->is_super_admin)) {
                    return;
                }
                $company = Company::with('plan')->find($companyId);
                if ($company) {
                    app(PlanGuard::class)->assertCanAdd($company, $resource);
                }
            };
        };
        User::creating($limit('users'));
        Branch::creating($limit('branches'));
        Warehouse::creating($limit('warehouses'));
        Employee::creating($limit('employees'));
        Invoice::creating($limit('invoices'));

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?? $request->ip());
        });
    }

    /** Account emails (verification, password reset) in Arabic, with the product name. */
    protected function arabicAuthMails(): void
    {
        VerifyEmail::toMailUsing(fn ($notifiable, string $url) => (new MailMessage)
            ->subject('تأكيد بريدك الإلكتروني — '.config('app.name'))
            ->greeting('مرحباً '.($notifiable->name ?? ''))
            ->line('شكراً لتسجيلك في '.config('app.name').'. اضغط الزر التالي لتأكيد بريدك الإلكتروني وتفعيل حسابك.')
            ->action('تأكيد البريد الإلكتروني', $url)
            ->line('صلاحية الرابط '.config('auth.verification.expire', 60).' دقيقة. إن لم تنشئ حساباً لدينا فتجاهل هذه الرسالة.'));

        ResetPassword::toMailUsing(fn ($notifiable, string $token) => (new MailMessage)
            ->subject('إعادة تعيين كلمة المرور — '.config('app.name'))
            ->greeting('مرحباً '.($notifiable->name ?? ''))
            ->line('وصلنا طلب لإعادة تعيين كلمة مرور حسابك.')
            ->action('تعيين كلمة مرور جديدة', url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false)))
            ->line('صلاحية الرابط '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60).' دقيقة.')
            ->line('إن لم تطلب ذلك فتجاهل هذه الرسالة، وكلمة مرورك الحالية تبقى كما هي.'));
    }
}

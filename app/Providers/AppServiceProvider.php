<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Services\TenantManager;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantManager::class, function () {
            return new TenantManager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();
        $this->registerTenantValidationRules();
        $this->registerSecurityEventLogging();
        $this->registerAuthorization();

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
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

            return DB::table($table)
                ->where($column, $value)
                ->where('company_id', $tenantId)
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

        // General API: max 120 per minute per user/IP
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?? $request->ip());
        });
    }
}

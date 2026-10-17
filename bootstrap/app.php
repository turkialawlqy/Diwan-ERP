<?php

use App\Http\Middleware\AuthorizeRoutePermission;
use App\Http\Middleware\EnsurePlanAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenant;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Services\Accounting\AccountingException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust proxy headers (X-Forwarded-Proto/For) only when explicitly configured,
        // so ForceHttps below can correctly detect HTTPS terminated at a reverse proxy
        // / load balancer instead of redirect-looping on every request.
        if ($trustedProxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(
                at: $trustedProxies === '*' ? '*' : explode(',', $trustedProxies),
            );
        }

        $middleware->web(prepend: [
            ForceHttps::class,
            SecurityHeaders::class,
        ], append: [
            SetLocale::class,
            ResolveTenant::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // A password change logs the account out of its other sessions.
            AuthenticateSession::class,
        ]);

        // ResolveTenant is appended to the `web` group above, but Laravel's default
        // middleware priority runs SubstituteBindings (which performs implicit route
        // model binding, e.g. Customer $client) BEFORE appended middleware. That let
        // route-model binding resolve ANY company's record before the tenant's
        // global scope was active, defeating tenant isolation on every show/update/
        // destroy route. Force ResolveTenant to run first so the scope is active
        // by the time models are bound from route parameters.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'super_admin' => EnsureSuperAdmin::class,
            'tenant' => EnsureTenant::class,
            'erp.authorize' => AuthorizeRoutePermission::class,
            'plan' => EnsurePlanAccess::class,
            'tenant.resolve' => ResolveTenant::class,
            'two_factor' => EnsureTwoFactorEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Accounting rule violations (unbalanced entry, closed period, missing
        // account mapping) are shown to the user like validation errors.
        $exceptions->map(
            AccountingException::class,
            fn (AccountingException $e) => $e->toValidation(),
        );
    })->create();

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central Domain
    |--------------------------------------------------------------------------
    |
    | The root domain the platform runs on. Every company is served from
    | "{subdomain}.{central_domain}". Requests to the bare central domain (or
    | one of the reserved subdomains below) carry no tenant.
    |
    */

    'central_domain' => env('APP_DOMAIN', 'localhost'),

    /*
    | Subdomains that can never be claimed by a company and never resolve to one.
    */
    'reserved_subdomains' => [
        'admin', 'api', 'www', 'mail', 'app', 'dashboard', 'localhost', '127',
        'static', 'assets', 'cdn', 'help', 'support', 'status', 'billing', 'docs',
        'ftp', 'smtp', 'imap', 'pop', 'ns1', 'ns2', 'test', 'dev', 'staging',
    ],

    /*
    | When true, an authenticated tenant user hitting the central domain is
    | redirected to their company's own subdomain instead of being served there.
    | Enable in production once wildcard DNS / SSL is configured.
    */
    'enforce_subdomain' => (bool) env('TENANCY_ENFORCE_SUBDOMAIN', false),

    /*
    | Allow the "X-Tenant" header / "?tenant=" query parameter to select a
    | tenant. Only ever honoured in the local and testing environments.
    */
    'allow_dev_override' => (bool) env('TENANCY_ALLOW_DEV_OVERRIDE', true),

    /*
    | Reject requests whose Host is neither the central domain, a company
    | subdomain, a verified custom domain nor listed in allowed_hosts. On by
    | default in production: password-reset links are built from the Host.
    | Turn off for a LAN install reached by IP / machine name.
    */
    'reject_unknown_hosts' => in_array(env('TENANCY_REJECT_UNKNOWN_HOSTS'), [null, ''], true) ? env('APP_ENV') === 'production' : (bool) env('TENANCY_REJECT_UNKNOWN_HOSTS'),

    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) env('TENANCY_ALLOWED_HOSTS', '')))))),

    /*
    | Request rate limits for company routes (requests per minute).
    */
    /*
    | Minutes a super admin impersonation session stays valid.
    */
    'impersonation_minutes' => (int) env('TENANCY_IMPERSONATION_MINUTES', 60),

    'rate_limit_per_user' => (int) env('TENANCY_RATE_LIMIT_USER', 300),
    'rate_limit_per_company' => (int) env('TENANCY_RATE_LIMIT_COMPANY', 3000),

];

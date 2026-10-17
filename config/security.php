<?php

return [
    /*
    | Platform administrators can reach every company's data, so two-factor
    | authentication is mandatory for them unless explicitly turned off.
    | Company users follow their company's policy (Settings → Security).
    */
    'two_factor_super_admin' => (bool) env('TWO_FACTOR_SUPER_ADMIN', true),

    /*
    | Redirect HTTP to HTTPS and generate https URLs. On by default in
    | production; turn off for a LAN install served over plain HTTP.
    */
    'force_https' => in_array(env('FORCE_HTTPS'), [null, ''], true) ? env('APP_ENV') === 'production' : (bool) env('FORCE_HTTPS'),

    /*
    | Two-factor challenge: failed codes before a lockout, and its minutes
    | (doubling on each further lockout of the day).
    */
    'two_factor_max_attempts' => (int) env('TWO_FACTOR_MAX_ATTEMPTS', 5),
    'two_factor_lockout_minutes' => (int) env('TWO_FACTOR_LOCKOUT_MINUTES', 15),
];

<?php

return [
    /*
    | Platform administrators can reach every company's data, so two-factor
    | authentication is mandatory for them unless explicitly turned off.
    | Company users follow their company's policy (Settings → Security).
    */
    'two_factor_super_admin' => (bool) env('TWO_FACTOR_SUPER_ADMIN', true),
];

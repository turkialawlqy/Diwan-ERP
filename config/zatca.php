<?php

return [
    // Fatoora portal gateways per environment.
    'endpoints' => [
        'sandbox' => env('ZATCA_SANDBOX_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal'),
        'simulation' => env('ZATCA_SIMULATION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation'),
        'production' => env('ZATCA_PRODUCTION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core'),
    ],

    // Certificate template requested in the CSR per environment.
    'certificate_templates' => [
        'sandbox' => 'TSTZATCA-Code-Signing',
        'simulation' => 'PREZATCA-Code-Signing',
        'production' => 'ZATCA-Code-Signing',
    ],

    'solution_name' => env('ZATCA_SOLUTION_NAME', 'Alostaz'),
    'solution_version' => env('ZATCA_SOLUTION_VERSION', 'ERP1'),

    'timeout' => (int) env('ZATCA_TIMEOUT', 30),

    // Simplified invoices must be reported within 24 hours.
    'reporting_deadline_hours' => 24,
];

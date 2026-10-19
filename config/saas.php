<?php

return [
    // Route name prefix => module a plan must include.
    'route_modules' => [
        'sales.' => 'sales',
        'pos.' => 'pos',
        'inventory.' => 'inventory',
        'purchases.' => 'purchases',
        'finance.' => 'accounting',
        'accounting.' => 'accounting',
        'projects.' => 'projects',
        'equipment.' => 'equipment',
        'hr.' => 'hr',
        'zatca.' => 'zatca',
        'crm.' => 'crm',
        'api.' => 'api',
        'webhooks.' => 'api',
    ],

    'modules' => [
        'sales' => 'المبيعات', 'pos' => 'نقاط البيع', 'inventory' => 'المستودعات', 'purchases' => 'المشتريات',
        'accounting' => 'المحاسبة والمالية', 'projects' => 'المقاولات والمشاريع', 'equipment' => 'المعدات والتأجير والصيانة', 'hr' => 'الموارد البشرية والرواتب',
        'zatca' => 'الربط مع فاتورة (المرحلة الثانية)', 'crm' => 'إدارة العملاء المحتملين', 'api' => 'الواجهة البرمجية API',
    ],

    // Days a lapsed subscription keeps working before the company becomes read-only.
    'grace_days' => 7,

    // Routes still writable for a lapsed subscription.
    'always_writable' => ['billing.', 'logout', 'profile.', 'locale.', 'notifications.'],

    'vat_rate' => 0.15,

    // The platform as the seller on subscription invoices, and where customers pay.
    'platform' => [
        'name' => env('PLATFORM_LEGAL_NAME', env('APP_NAME', 'الديوان')),
        'tax_number' => env('PLATFORM_TAX_NUMBER'),
        'commercial_registration' => env('PLATFORM_CR'),
        'address' => env('PLATFORM_ADDRESS'),
        'email' => env('PLATFORM_BILLING_EMAIL'),
        'bank_name' => env('PLATFORM_BANK_NAME'),
        'account_name' => env('PLATFORM_BANK_ACCOUNT_NAME'),
        'iban' => env('PLATFORM_IBAN'),
    ],

    // Days before the trial / subscription end on which owners are emailed (0 = the day itself).
    'reminder_days' => [7, 3, 0],

    // Outgoing webhooks
    'webhook_events' => [
        'invoice.created' => 'إصدار فاتورة مبيعات',
        'payment.received' => 'تحصيل سند قبض',
        'credit_note.created' => 'إصدار إشعار دائن / مرتجع',
        'zatca.status_changed' => 'تغير حالة الفاتورة لدى هيئة الزكاة',
        'stock.low' => 'وصول صنف لحد إعادة الطلب',
    ],
];

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
        'hr.' => 'hr',
        'zatca.' => 'zatca',
        'crm.' => 'crm',
        'api.' => 'api',
        'webhooks.' => 'api',
    ],

    'modules' => [
        'sales' => 'المبيعات', 'pos' => 'نقاط البيع', 'inventory' => 'المستودعات', 'purchases' => 'المشتريات',
        'accounting' => 'المحاسبة والمالية', 'projects' => 'المقاولات والمشاريع', 'hr' => 'الموارد البشرية والرواتب',
        'zatca' => 'الربط مع فاتورة (المرحلة الثانية)', 'crm' => 'إدارة العملاء المحتملين', 'api' => 'الواجهة البرمجية API',
    ],

    // Days a lapsed subscription keeps working before the company becomes read-only.
    'grace_days' => 7,

    // Routes still writable for a lapsed subscription.
    'always_writable' => ['billing.', 'logout', 'profile.', 'locale.', 'notifications.'],

    'vat_rate' => 0.15,

    // Outgoing webhooks
    'webhook_events' => [
        'invoice.created' => 'إصدار فاتورة مبيعات',
        'payment.received' => 'تحصيل سند قبض',
        'credit_note.created' => 'إصدار إشعار دائن / مرتجع',
        'zatca.status_changed' => 'تغير حالة الفاتورة لدى هيئة الزكاة',
        'stock.low' => 'وصول صنف لحد إعادة الطلب',
    ],
];

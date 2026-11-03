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
        'manufacturing.' => 'manufacturing',
        'hr.' => 'hr',
        'zatca.' => 'zatca',
        'crm.' => 'crm',
        'support.' => 'support',
        'contracts.' => 'contracts',
        'portal.' => 'portal',
        'bookings.' => 'bookings',
        'work-orders.' => 'work_orders',
        'clinic.' => 'clinic',
        'gym.' => 'gym',
        'workshop.' => 'workshop',
        'real-estate.' => 'real_estate',
        'api.' => 'api',
        'webhooks.' => 'api',
    ],

    'modules' => [
        'sales' => 'المبيعات', 'pos' => 'نقاط البيع', 'inventory' => 'المستودعات', 'purchases' => 'المشتريات',
        'accounting' => 'المحاسبة والمالية', 'projects' => 'المقاولات والمشاريع', 'equipment' => 'المعدات والتأجير والصيانة', 'manufacturing' => 'التصنيع والمصانع', 'hr' => 'الموارد البشرية والرواتب',
        'zatca' => 'الربط مع فاتورة (المرحلة الثانية)', 'crm' => 'إدارة العملاء المحتملين', 'support' => 'خدمة العملاء والتذاكر', 'contracts' => 'إدارة العقود', 'portal' => 'بوابة العملاء', 'bookings' => 'الحجوزات والمواعيد', 'work_orders' => 'أوامر العمل الميدانية', 'clinic' => 'العيادات والمراكز الطبية', 'gym' => 'النوادي والمراكز الرياضية', 'workshop' => 'الورش وصيانة السيارات', 'real_estate' => 'العقارات وإدارة الأملاك', 'api' => 'الواجهة البرمجية API',
    ],

    // Modules a company may switch off itself (الإعدادات ← الوحدات): their menus, pages and search disappear, the data stays.
    'optional_modules' => [
        'manufacturing' => 'نظام المصانع (التصنيع، تخطيط الاحتياجات، مراكز العمل، الجودة)',
        'projects' => 'المقاولات والمشاريع (المستخلصات، مقاولو الباطن، الضمانات)',
        'equipment' => 'المعدات (التأجير، القراءات، الصيانة)',
        'pos' => 'نقاط البيع (الكاشير والورديات)',
        'hr' => 'الموارد البشرية والرواتب',
        'crm' => 'إدارة العملاء المحتملين',
        'support' => 'خدمة العملاء (التذاكر واتفاقيات مستوى الخدمة)',
        'contracts' => 'إدارة العقود (النماذج والإصدارات والاعتماد والتجديد والالتزامات)',
        'portal' => 'بوابة العملاء (الفواتير والتذاكر والعقود والاشتراكات والمشاريع)',
        'bookings' => 'الحجوزات والمواعيد (الموارد والخدمات والتقويم وباقات الزيارات)',
        'work_orders' => 'أوامر العمل الميدانية (الفنيون والوقت والقطع والتوقيع والفوترة)',
        'clinic' => 'العيادات (ملف المريض وسجل الزيارات)',
        'gym' => 'النوادي الرياضية (تسجيل الحضور والتحقق من العضوية)',
        'workshop' => 'الورش (بطاقة السيارة وتذكير الصيانة الدورية)',
        'real_estate' => 'العقارات (الوحدات وعقود الإيجار وفوترة الإيجار)',
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

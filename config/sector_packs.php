<?php

/*
| «حزم القطاعات»: one click seeds ready-made services, plans, work types, categories and contract
| templates for a sector on top of the generic blocks. Prices are samples the owner edits afterwards.
| Applying is idempotent (matched by name), so it can be run again without duplicates.
*/

$week = [6, 7, 1, 2, 3, 4]; // Saturday to Thursday

return [
    'version' => 1,

    'packs' => [
        'clinic' => [
            'label' => 'العيادات والمراكز الطبية',
            'description' => 'ملف المريض وسجل الزيارات، وحجز مواعيد الأطباء، وباقات الجلسات، وفوترة الكشوفات.',
            'modules' => ['clinic', 'bookings'],
            'resources' => [
                ['name' => 'الطبيب العام', 'type' => 'staff', 'hours' => [['09:00', '13:00'], ['16:00', '20:00']], 'days' => $week],
                ['name' => 'غرفة الكشف 1', 'type' => 'room', 'hours' => [['09:00', '13:00'], ['16:00', '20:00']], 'days' => $week],
            ],
            'bookable_services' => [
                ['name' => 'كشف', 'duration_minutes' => 30, 'price' => 150, 'deposit_percent' => 0, 'free_cancel_hours' => 12],
                ['name' => 'استشارة', 'duration_minutes' => 20, 'price' => 100, 'deposit_percent' => 0, 'free_cancel_hours' => 12],
                ['name' => 'مراجعة / متابعة', 'duration_minutes' => 15, 'price' => 60, 'deposit_percent' => 0, 'free_cancel_hours' => 6],
                ['name' => 'جلسة علاجية', 'duration_minutes' => 45, 'price' => 200, 'deposit_percent' => 20, 'free_cancel_hours' => 24],
            ],
            'visit_packages' => [
                ['name' => 'باقة 5 جلسات علاجية', 'service' => 'جلسة علاجية', 'visits' => 5, 'price' => 900, 'valid_days' => 120],
            ],
            'support_categories' => ['استفسار طبي', 'مواعيد', 'فواتير وتأمين'],
            'contract_templates' => [
                ['name' => 'اتفاقية خدمات طبية للشركات', 'kind' => 'service', 'body' => "1. يقدّم المركز الطبي لموظفي الطرف الثاني الخدمات الطبية المتفق عليها وفق قائمة الأسعار المرفقة.\n2. تُسدَّد الفواتير خلال 30 يومًا من تاريخ إصدارها.\n3. يلتزم المركز بسرية المعلومات الطبية للمرضى وفق الأنظمة المعمول بها.\n4. مدة الاتفاقية سنة قابلة للتجديد باتفاق الطرفين."],
            ],
        ],

        'gym' => [
            'label' => 'النوادي والمراكز الرياضية',
            'description' => 'عضويات زمنية بالاشتراكات، وباقات حصص، وتسجيل حضور بالباركود يتحقق من سريان العضوية.',
            'modules' => ['gym', 'bookings'],
            'resources' => [
                ['name' => 'قاعة التمارين', 'type' => 'room', 'capacity' => 20, 'hours' => [['06:00', '23:00']], 'days' => [6, 7, 1, 2, 3, 4, 5]],
                ['name' => 'المدرب الشخصي', 'type' => 'staff', 'hours' => [['07:00', '12:00'], ['16:00', '22:00']], 'days' => $week],
            ],
            'bookable_services' => [
                ['name' => 'حصة جماعية', 'duration_minutes' => 60, 'price' => 40, 'deposit_percent' => 0, 'free_cancel_hours' => 4],
                ['name' => 'تدريب شخصي', 'duration_minutes' => 60, 'price' => 150, 'deposit_percent' => 0, 'free_cancel_hours' => 12],
            ],
            'visit_packages' => [
                ['name' => 'باقة 12 حصة', 'service' => null, 'visits' => 12, 'price' => 400, 'valid_days' => 60],
                ['name' => 'باقة 10 جلسات تدريب شخصي', 'service' => 'تدريب شخصي', 'visits' => 10, 'price' => 1300, 'valid_days' => 90],
            ],
            'service_plans' => [
                ['name' => 'عضوية شهرية', 'price' => 250, 'cycle' => 'monthly', 'booking_discount_percent' => 50],
                ['name' => 'عضوية ربع سنوية', 'price' => 650, 'cycle' => 'quarterly', 'booking_discount_percent' => 50],
                ['name' => 'عضوية سنوية', 'price' => 2200, 'cycle' => 'yearly', 'booking_discount_percent' => 100],
            ],
            'support_categories' => ['العضويات', 'الحصص والمدربون'],
            'contract_templates' => [
                ['name' => 'عقد عضوية نادٍ', 'kind' => 'service', 'body' => "1. يمنح النادي العضو حق استخدام المرافق خلال ساعات العمل المعلنة طوال مدة العضوية.\n2. لا تُرَدّ رسوم العضوية بعد بدء الاستفادة منها، ويمكن تجميدها بطلب مسبق.\n3. يلتزم العضو بتعليمات السلامة والنظام الداخلي للنادي.\n4. لا يتحمل النادي مسؤولية فقدان المتعلقات الشخصية."],
            ],
        ],

        'workshop' => [
            'label' => 'الورش وصيانة السيارات',
            'description' => 'بطاقة السيارة وأوامر الإصلاح، وفحص الاستلام وقراءة العداد، وتذكير العملاء بموعد الصيانة الدورية.',
            'modules' => ['workshop', 'work_orders'],
            'work_types' => [
                ['name' => 'فحص شامل', 'hourly_rate' => 120, 'min_billable_minutes' => 30, 'service_interval_days' => 180, 'service_interval_km' => 10000],
                ['name' => 'تغيير زيت وفلتر', 'hourly_rate' => 100, 'min_billable_minutes' => 30, 'service_interval_days' => 120, 'service_interval_km' => 5000],
                ['name' => 'صيانة فرامل', 'hourly_rate' => 130, 'min_billable_minutes' => 60, 'service_interval_days' => 365, 'service_interval_km' => 30000],
                ['name' => 'كهرباء السيارات', 'hourly_rate' => 140, 'min_billable_minutes' => 60],
                ['name' => 'سمكرة ودهان', 'hourly_rate' => 150, 'min_billable_minutes' => 120],
                ['name' => 'تكييف السيارات', 'hourly_rate' => 130, 'min_billable_minutes' => 60, 'service_interval_days' => 365],
            ],
            'support_categories' => ['طلب موعد صيانة', 'شكوى على إصلاح'],
            'contract_templates' => [
                ['name' => 'اتفاقية صيانة أسطول', 'kind' => 'service', 'body' => "1. تتولى الورشة الصيانة الدورية والطارئة لمركبات الطرف الثاني وفق جدول الأعمال والأسعار المرفقين.\n2. تُمنح مركبات الأسطول أولوية في المواعيد.\n3. تضمن الورشة أعمالها لمدة 3 أشهر أو 5000 كم أيهما أسبق.\n4. تُسدَّد الفواتير شهريًا."],
            ],
        ],

        'real_estate' => [
            'label' => 'العقارات وإدارة الأملاك',
            'description' => 'العقارات والوحدات والمستأجرون، وعقد الإيجار مع فوترة الإيجار الدورية تلقائيًا، وطلبات الصيانة للوحدات.',
            'modules' => ['real_estate', 'contracts'],
            'service_plans' => [
                ['name' => 'إيجار شهري', 'price' => 0, 'cycle' => 'monthly'],
                ['name' => 'إيجار ربع سنوي', 'price' => 0, 'cycle' => 'quarterly'],
                ['name' => 'إيجار سنوي', 'price' => 0, 'cycle' => 'yearly'],
            ],
            'work_types' => [
                ['name' => 'صيانة سباكة', 'hourly_rate' => 90, 'min_billable_minutes' => 60],
                ['name' => 'صيانة كهرباء', 'hourly_rate' => 90, 'min_billable_minutes' => 60],
                ['name' => 'صيانة تكييف', 'hourly_rate' => 110, 'min_billable_minutes' => 60],
            ],
            'support_categories' => ['طلب صيانة وحدة', 'شكوى مستأجر', 'سداد الإيجار'],
            'contract_templates' => [
                ['name' => 'عقد إيجار وحدة سكنية', 'kind' => 'lease', 'body' => "1. يؤجّر الطرف الأول الوحدة الموصوفة في العقد للطرف الثاني للسكن فقط طوال مدة العقد.\n2. يسدّد المستأجر الإيجار في مواعيده، وعليه مبلغ تأمين يُرَدّ عند التسليم بحالة سليمة.\n3. لا يجوز التأجير من الباطن إلا بموافقة كتابية.\n4. يتحمل المؤجر الصيانة الإنشائية ويتحمل المستأجر الصيانة التشغيلية البسيطة.\n5. يُجدَّد العقد باتفاق الطرفين قبل انتهائه بمدة لا تقل عن 60 يومًا."],
                ['name' => 'عقد إيجار محل تجاري', 'kind' => 'lease', 'body' => "1. يؤجّر الطرف الأول المحل الموصوف في العقد للطرف الثاني لمزاولة النشاط التجاري المتفق عليه.\n2. يسدّد المستأجر الإيجار في مواعيده ويلتزم بتراخيص النشاط.\n3. لا يجوز تغيير النشاط أو التأجير من الباطن إلا بموافقة كتابية.\n4. يُجدَّد العقد باتفاق الطرفين قبل انتهائه بمدة لا تقل عن 60 يومًا."],
            ],
        ],
    ],
];

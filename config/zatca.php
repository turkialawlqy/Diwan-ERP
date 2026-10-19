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

    'solution_name' => env('ZATCA_SOLUTION_NAME', 'Diwan'),
    'solution_version' => env('ZATCA_SOLUTION_VERSION', 'ERP1'),

    'timeout' => (int) env('ZATCA_TIMEOUT', 30),

    // Simplified invoices must be reported within 24 hours.
    'reporting_deadline_hours' => 24,

    // VAT categories and the official exemption / zero-rating reason codes
    // (ZATCA e-invoicing data dictionary). Category S needs no reason.
    'vat_categories' => [
        'S' => 'خاضع بالنسبة الأساسية',
        'Z' => 'خاضع بنسبة صفر',
        'E' => 'معفى',
        'O' => 'خارج نطاق الضريبة',
    ],

    'exemption_reasons' => [
        'Z' => [
            'VATEX-SA-32' => ['ar' => 'صادرات السلع من المملكة', 'en' => 'Export of goods'],
            'VATEX-SA-33' => ['ar' => 'صادرات الخدمات من المملكة', 'en' => 'Export of services'],
            'VATEX-SA-34-1' => ['ar' => 'النقل الدولي للسلع', 'en' => 'The international transport of Goods'],
            'VATEX-SA-34-2' => ['ar' => 'النقل الدولي للركاب', 'en' => 'International transport of passengers'],
            'VATEX-SA-34-3' => ['ar' => 'الخدمات المرتبطة مباشرة بالنقل الدولي للركاب', 'en' => 'Services directly connected and incidental to a Supply of international passenger transport'],
            'VATEX-SA-34-4' => ['ar' => 'توريد وسائل النقل المؤهلة', 'en' => 'Supply of a qualifying means of transport'],
            'VATEX-SA-34-5' => ['ar' => 'الخدمات ذات الصلة بنقل السلع أو الركاب', 'en' => 'Any services relating to Goods or passenger transportation'],
            'VATEX-SA-35' => ['ar' => 'الأدوية والمعدات الطبية', 'en' => 'Medicines and medical equipment'],
            'VATEX-SA-36' => ['ar' => 'المعادن المؤهلة', 'en' => 'Qualifying metals'],
            'VATEX-SA-EDU' => ['ar' => 'الخدمات التعليمية الخاصة للمواطنين', 'en' => 'Private education to citizen'],
            'VATEX-SA-HEA' => ['ar' => 'الخدمات الصحية الخاصة للمواطنين', 'en' => 'Private healthcare to citizen'],
            'VATEX-SA-MLTRY' => ['ar' => 'توريد السلع والخدمات العسكرية المؤهلة', 'en' => 'Supply of qualified military goods'],
        ],
        'E' => [
            'VATEX-SA-29' => ['ar' => 'الخدمات المالية', 'en' => 'Financial services mentioned in Article 29 of the VAT Regulations'],
            'VATEX-SA-29-7' => ['ar' => 'عقد تأمين على الحياة أو إعادة تأمينه', 'en' => 'Life insurance services mentioned in Article 29 of the VAT Regulations'],
            'VATEX-SA-30' => ['ar' => 'التوريدات العقارية المعفاة', 'en' => 'Real estate transactions mentioned in Article 30 of the VAT Regulations'],
        ],
        'O' => [
            'VATEX-SA-OOS' => ['ar' => 'خارج نطاق الضريبة', 'en' => 'Not subject to VAT'],
        ],
    ],
];

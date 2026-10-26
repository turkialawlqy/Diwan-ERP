<?php

/*
 * Countries a branch can operate in: currency, indirect tax and its rules,
 * the tax registration number, the government e-invoicing system, time zone
 * and weekend. Rates are defaults: a company can override them (with an
 * effective date) from the tax settings; check them with a local accountant
 * before going live in a new country.
 *
 * tax.has: false = no general VAT / sales tax (documents carry no tax).
 * tax.rates: rates a standard-rated line may use (the first is the standard).
 * einvoicing: the government system documents are cleared / reported with
 *   (zatca is integrated; the others are planned and print without a link).
 * weekend: ISO-8601 day numbers (5 = Friday, 6 = Saturday, 7 = Sunday).
 */

$vat = fn (float $standard, array $reduced = [], string $name = 'ضريبة القيمة المضافة', string $short = 'ض.ق.م') => [
    'has' => true, 'name' => $name, 'short' => $short, 'standard' => $standard, 'rates' => array_values(array_unique([$standard, ...$reduced])),
];
$none = ['has' => false, 'name' => 'لا توجد ضريبة قيمة مضافة', 'short' => '', 'standard' => 0.0, 'rates' => []];
$taxId = fn (string $label, string $pattern, string $example) => ['label' => $label, 'pattern' => $pattern, 'example' => $example];
$freeId = ['label' => 'الرقم الضريبي', 'pattern' => '/^[A-Za-z0-9\-\/]{4,20}$/', 'example' => ''];

return [
    'SA' => [
        'name' => 'المملكة العربية السعودية', 'currency' => 'SAR', 'currency_name' => 'ريال سعودي', 'fraction' => 'هللة', 'symbol' => 'ر.س', 'decimals' => 2,
        'tax' => $vat(15), 'tax_number' => $taxId('الرقم الضريبي', '/^3\d{13}3$/', '310123456700003'),
        'einvoicing' => 'zatca', 'timezone' => 'Asia/Riyadh', 'weekend' => [5, 6], 'national_address' => true, 'simplified_invoices' => true,
    ],
    'AE' => [
        'name' => 'الإمارات العربية المتحدة', 'currency' => 'AED', 'currency_name' => 'درهم إماراتي', 'fraction' => 'فلس', 'symbol' => 'د.إ', 'decimals' => 2,
        'tax' => $vat(5), 'tax_number' => $taxId('رقم التسجيل الضريبي (TRN)', '/^1\d{14}$/', '100123456700003'),
        'einvoicing' => 'uae', 'timezone' => 'Asia/Dubai', 'weekend' => [6, 7], 'national_address' => false, 'simplified_invoices' => true,
    ],
    'BH' => [
        'name' => 'البحرين', 'currency' => 'BHD', 'currency_name' => 'دينار بحريني', 'fraction' => 'فلس', 'symbol' => 'د.ب', 'decimals' => 3,
        'tax' => $vat(10), 'tax_number' => $taxId('رقم حساب ضريبة القيمة المضافة', '/^\d{15}$/', '200012345600002'),
        'einvoicing' => null, 'timezone' => 'Asia/Bahrain', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => true,
    ],
    'OM' => [
        'name' => 'سلطنة عُمان', 'currency' => 'OMR', 'currency_name' => 'ريال عماني', 'fraction' => 'بيسة', 'symbol' => 'ر.ع', 'decimals' => 3,
        'tax' => $vat(5), 'tax_number' => $taxId('رقم التسجيل الضريبي', '/^OM\d{10}$/', 'OM1100123456'),
        'einvoicing' => 'fawtara', 'timezone' => 'Asia/Muscat', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => true,
    ],
    'QA' => [
        'name' => 'قطر', 'currency' => 'QAR', 'currency_name' => 'ريال قطري', 'fraction' => 'درهم', 'symbol' => 'ر.ق', 'decimals' => 2,
        'tax' => $none, 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Asia/Qatar', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'KW' => [
        'name' => 'الكويت', 'currency' => 'KWD', 'currency_name' => 'دينار كويتي', 'fraction' => 'فلس', 'symbol' => 'د.ك', 'decimals' => 3,
        'tax' => $none, 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Asia/Kuwait', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'EG' => [
        'name' => 'مصر', 'currency' => 'EGP', 'currency_name' => 'جنيه مصري', 'fraction' => 'قرش', 'symbol' => 'ج.م', 'decimals' => 2,
        'tax' => $vat(14, [5]), 'tax_number' => $taxId('رقم التسجيل الضريبي', '/^\d{9}$/', '123456789'),
        'einvoicing' => 'eta', 'timezone' => 'Africa/Cairo', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => true,
    ],
    'JO' => [
        'name' => 'الأردن', 'currency' => 'JOD', 'currency_name' => 'دينار أردني', 'fraction' => 'فلس', 'symbol' => 'د.أ', 'decimals' => 3,
        'tax' => $vat(16, [10, 8, 5, 4, 2, 1], 'الضريبة العامة على المبيعات', 'ض.ع.م'), 'tax_number' => $taxId('الرقم الضريبي', '/^\d{6,10}$/', '12345678'),
        'einvoicing' => 'jofotara', 'timezone' => 'Asia/Amman', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => true,
    ],
    'YE' => [
        'name' => 'اليمن', 'currency' => 'YER', 'currency_name' => 'ريال يمني', 'fraction' => 'فلس', 'symbol' => 'ر.ي', 'decimals' => 2,
        'tax' => $vat(5, [10], 'الضريبة العامة على المبيعات', 'ض.ع.م'), 'tax_number' => $taxId('الرقم الضريبي', '/^\d{6,15}$/', '1234567'),
        'einvoicing' => null, 'timezone' => 'Asia/Aden', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'DZ' => [
        'name' => 'الجزائر', 'currency' => 'DZD', 'currency_name' => 'دينار جزائري', 'fraction' => 'سنتيم', 'symbol' => 'د.ج', 'decimals' => 2,
        'tax' => $vat(19, [9], 'الرسم على القيمة المضافة', 'ر.ق.م'), 'tax_number' => $taxId('رقم التعريف الجبائي (NIF)', '/^\d{15,20}$/', '000016001234567'),
        'einvoicing' => null, 'timezone' => 'Africa/Algiers', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'MA' => [
        'name' => 'المغرب', 'currency' => 'MAD', 'currency_name' => 'درهم مغربي', 'fraction' => 'سنتيم', 'symbol' => 'د.م', 'decimals' => 2,
        'tax' => $vat(20, [14, 10, 7], 'الضريبة على القيمة المضافة', 'ض.ق.م'), 'tax_number' => $taxId('المعرف الموحد للمقاولة (ICE)', '/^\d{15}$/', '001234567000089'),
        'einvoicing' => null, 'timezone' => 'Africa/Casablanca', 'weekend' => [6, 7], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'TN' => [
        'name' => 'تونس', 'currency' => 'TND', 'currency_name' => 'دينار تونسي', 'fraction' => 'مليم', 'symbol' => 'د.ت', 'decimals' => 3,
        'tax' => $vat(19, [13, 7], 'الأداء على القيمة المضافة', 'أ.ق.م'), 'tax_number' => $taxId('المعرف الجبائي', '/^[0-9]{7}[A-Za-z]([\/ ]?[A-Za-z][\/ ]?[A-Za-z][\/ ]?\d{3})?$/', '1234567A/M/000'),
        'einvoicing' => null, 'timezone' => 'Africa/Tunis', 'weekend' => [6, 7], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'LB' => [
        'name' => 'لبنان', 'currency' => 'LBP', 'currency_name' => 'ليرة لبنانية', 'fraction' => 'قرش', 'symbol' => 'ل.ل', 'decimals' => 2,
        'tax' => $vat(11), 'tax_number' => $taxId('الرقم المالي', '/^\d{1,12}$/', '123456'),
        'einvoicing' => null, 'timezone' => 'Asia/Beirut', 'weekend' => [6, 7], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'PS' => [
        'name' => 'فلسطين', 'currency' => 'ILS', 'currency_name' => 'شيكل', 'fraction' => 'أغورة', 'symbol' => '₪', 'decimals' => 2,
        'tax' => $vat(16), 'tax_number' => $taxId('رقم المشتغل', '/^\d{9}$/', '562345678'),
        'einvoicing' => null, 'timezone' => 'Asia/Hebron', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'SD' => [
        'name' => 'السودان', 'currency' => 'SDG', 'currency_name' => 'جنيه سوداني', 'fraction' => 'قرش', 'symbol' => 'ج.س', 'decimals' => 2,
        'tax' => $vat(17), 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Africa/Khartoum', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'MR' => [
        'name' => 'موريتانيا', 'currency' => 'MRU', 'currency_name' => 'أوقية', 'fraction' => 'خُمس', 'symbol' => 'أ.م', 'decimals' => 2,
        'tax' => $vat(16, [], 'الرسم على القيمة المضافة', 'ر.ق.م'), 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Africa/Nouakchott', 'weekend' => [6, 7], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'DJ' => [
        'name' => 'جيبوتي', 'currency' => 'DJF', 'currency_name' => 'فرنك جيبوتي', 'fraction' => 'سنتيم', 'symbol' => 'ف.ج', 'decimals' => 2,
        'tax' => $vat(10), 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Africa/Djibouti', 'weekend' => [5], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'IQ' => [
        'name' => 'العراق', 'currency' => 'IQD', 'currency_name' => 'دينار عراقي', 'fraction' => 'فلس', 'symbol' => 'د.ع', 'decimals' => 2,
        'tax' => $none, 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Asia/Baghdad', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'SY' => [
        'name' => 'سوريا', 'currency' => 'SYP', 'currency_name' => 'ليرة سورية', 'fraction' => 'قرش', 'symbol' => 'ل.س', 'decimals' => 2,
        'tax' => $none, 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Asia/Damascus', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'LY' => [
        'name' => 'ليبيا', 'currency' => 'LYD', 'currency_name' => 'دينار ليبي', 'fraction' => 'درهم', 'symbol' => 'د.ل', 'decimals' => 3,
        'tax' => $none, 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Africa/Tripoli', 'weekend' => [5, 6], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'SO' => [
        'name' => 'الصومال', 'currency' => 'SOS', 'currency_name' => 'شلن صومالي', 'fraction' => 'سنت', 'symbol' => 'ش.ص', 'decimals' => 2,
        'tax' => $none, 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Africa/Mogadishu', 'weekend' => [5], 'national_address' => false, 'simplified_invoices' => false,
    ],
    'KM' => [
        'name' => 'جزر القمر', 'currency' => 'KMF', 'currency_name' => 'فرنك قمري', 'fraction' => 'سنتيم', 'symbol' => 'ف.ق', 'decimals' => 2,
        'tax' => $none, 'tax_number' => $freeId,
        'einvoicing' => null, 'timezone' => 'Indian/Comoro', 'weekend' => [5], 'national_address' => false, 'simplified_invoices' => false,
    ],
];

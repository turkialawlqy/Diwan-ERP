<?php

/*
| Saudi Labour Law & GOSI parameters. Rates change by regulation: review
| them with each GOSI circular; companies can override with settings keys
| of the same name (e.g. hr.gosi.saudi_employee_rate).
*/
return [
    'month_days' => 30,          // salary month for daily rates
    'hours_per_day' => 8,         // Art. 98
    'overtime_premium' => 0.5,    // Art. 107: hourly wage + 50% of the basic hourly wage

    'gosi' => [
        'min_wage' => 1500,
        'max_wage' => 45000,
        // Saudis: annuities (pension) + SANED (unemployment) + occupational hazards (employer only).
        'saudi_employee_rate' => 0.0975,   // 9% annuity + 0.75% SANED
        'saudi_employer_rate' => 0.1175,   // 9% annuity + 0.75% SANED + 2% hazards
        // Non-Saudis: occupational hazards only, paid by the employer.
        'non_saudi_employee_rate' => 0.0,
        'non_saudi_employer_rate' => 0.02,
        // New Social Insurance Law (from 3 July 2024): annuity rate for new
        // entrants rises by 0.5% each July on both sides until 11%.
        'base_annuity_rate' => 0.09,  // annuity share included in the Saudi rates above
        'new_law_start' => '2024-07-03',
        'new_entrant_annuity' => [
            '2025-07-01' => 0.095,
            '2026-07-01' => 0.10,
            '2027-07-01' => 0.105,
            '2028-07-01' => 0.11,
        ],
    ],

    // Art. 117: sick leave pay tiers within a year of service.
    'sick_tiers' => [[30, 1.0], [60, 0.75], [30, 0.0]],

    // Art. 109: 21 days, 30 after five years of service.
    'annual_leave' => ['days' => 21, 'senior_days' => 30, 'senior_after_years' => 5],

    // Art. 84 / 85.
    'end_of_service' => [
        'first_years' => 5,
        'first_rate' => 0.5,  // half a month per year for the first five years
        'later_rate' => 1.0,  // a full month per year afterwards
        'resignation' => [[2, 0.0], [5, 1 / 3], [10, 2 / 3]], // [years below, share]; 10+ => full
    ],

    // Art. 92: deductions for loans / penalties may not exceed half the wage.
    'max_deduction_ratio' => 0.5,

    'default_leave_types' => [
        ['code' => 'annual', 'name' => 'إجازة سنوية', 'days_per_year' => 21, 'pay_rule' => 'full', 'deducts_balance' => true],
        ['code' => 'sick', 'name' => 'إجازة مرضية', 'days_per_year' => 120, 'pay_rule' => 'sick_tiers', 'deducts_balance' => false],
        ['code' => 'unpaid', 'name' => 'إجازة بدون راتب', 'days_per_year' => 0, 'pay_rule' => 'unpaid', 'deducts_balance' => false],
        ['code' => 'maternity', 'name' => 'إجازة وضع', 'days_per_year' => 84, 'pay_rule' => 'full', 'deducts_balance' => false],
        ['code' => 'paternity', 'name' => 'إجازة مولود', 'days_per_year' => 3, 'pay_rule' => 'full', 'deducts_balance' => false],
        ['code' => 'marriage', 'name' => 'إجازة زواج', 'days_per_year' => 5, 'pay_rule' => 'full', 'deducts_balance' => false],
        ['code' => 'bereavement', 'name' => 'إجازة وفاة', 'days_per_year' => 5, 'pay_rule' => 'full', 'deducts_balance' => false],
        ['code' => 'hajj', 'name' => 'إجازة حج', 'days_per_year' => 15, 'pay_rule' => 'full', 'deducts_balance' => false],
        ['code' => 'exam', 'name' => 'إجازة امتحان', 'days_per_year' => 0, 'pay_rule' => 'full', 'deducts_balance' => false],
    ],

    // Wage Protection: establishment identifiers printed on the Mudad / bank
    // salary files, and the bank layout used for the bank upload file.
    'wps' => [
        'mol_establishment_id' => '',   // establishment number at the Ministry (labour office - sequence)
        'gosi_establishment_id' => '',
        'company_iban' => '',           // account the salaries are paid from
        'bank_format' => 'alrajhi',     // see App\Services\HR\BankSalaryFormats
    ],

    // Lateness deduction: only when the company's approved work regulation
    // provides for it. Minutes late beyond the monthly allowance are
    // deducted at the minute wage.
    'late_deduction' => [
        'enabled' => false,
        'monthly_grace_minutes' => 60,
    ],

    // Indicative Nitaqat weighting of a Saudi employee by monthly wage
    // (basic + housing): full count at or above full_wage, half count from
    // half_wage, not counted below. The official band comes from Qiwa.
    'saudization' => [
        'full_wage' => 4000,
        'half_wage' => 3000,
    ],
];

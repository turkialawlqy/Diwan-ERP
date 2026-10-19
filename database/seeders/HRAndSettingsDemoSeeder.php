<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\PrintTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class HRAndSettingsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();
        if (! $company) {
            return;
        }

        $adminUser = User::where('company_id', $company->id)->first() ?? User::first();

        // 1. الفروع (Branches)
        $mainBranch = Branch::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'BR-01'],
            [
                'name_ar' => 'الفرع الرئيسي - الرياض',
                'name_en' => 'Main Branch - Riyadh',
                'phone' => '0114977231',
                'address' => 'طريق الملك فهد، حي العليا',
                'city' => 'الرياض',
                'is_main' => true,
                'is_active' => true,
            ]
        );

        $jeddahBranch = Branch::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'BR-02'],
            [
                'name_ar' => 'فرع المنطقة الغربية - جدة',
                'name_en' => 'Jeddah Branch',
                'phone' => '0126543210',
                'address' => 'طريق المدينة المنورة، حي الروضة',
                'city' => 'جدة',
                'is_main' => false,
                'is_active' => true,
            ]
        );

        $dammamBranch = Branch::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'BR-03'],
            [
                'name_ar' => 'فرع المنطقة الشرقية - الدمام',
                'name_en' => 'Dammam Branch',
                'phone' => '0138765432',
                'address' => 'طريق الأمير محمد بن فهد، حي الشاطئ',
                'city' => 'الدمام',
                'is_main' => false,
                'is_active' => true,
            ]
        );

        // 2. الموظفين (Employees)
        $employeesData = [
            [
                'name' => 'أنس عبد الله القرني',
                'national_id' => '1089283741',
                'phone' => '0501112233',
                'email' => 'anas.q@diwan.io',
                'job_title' => 'الرئيس التنفيذي / المدير العام',
                'department' => 'الإدارة التنفيذية',
                'branch_id' => $mainBranch->id,
                'hire_date' => Carbon::parse('2024-01-01'),
                'basic_salary' => 15000.00,
                'housing_allowance' => 3750.00,
                'transport_allowance' => 1000.00,
                'other_allowances' => 0.00,
                'total_salary' => 19750.00,
                'bank_name' => 'مصرف الراجحي',
                'iban' => 'SA4480000100000000111111',
                'status' => 'active',
            ],
            [
                'name' => 'خالد محمد الغامدي',
                'national_id' => '1074829103',
                'phone' => '0552223344',
                'email' => 'khalid.g@diwan.io',
                'job_title' => 'مدير قطاع المبيعات والتسويق',
                'department' => 'المبيعات',
                'branch_id' => $mainBranch->id,
                'hire_date' => Carbon::parse('2024-06-15'),
                'basic_salary' => 9000.00,
                'housing_allowance' => 2250.00,
                'transport_allowance' => 800.00,
                'other_allowances' => 0.00,
                'total_salary' => 12050.00,
                'bank_name' => 'البنك الأهلي السعودي',
                'iban' => 'SA2210000200000000222222',
                'status' => 'active',
            ],
            [
                'name' => 'سارة أحمد العتيبي',
                'national_id' => '1093847291',
                'phone' => '0543334455',
                'email' => 'sara.o@diwan.io',
                'job_title' => 'أخصائية محاسبة ومالية أولى',
                'department' => 'المالية والمحاسبة',
                'branch_id' => $mainBranch->id,
                'hire_date' => Carbon::parse('2025-02-01'),
                'basic_salary' => 7500.00,
                'housing_allowance' => 1875.00,
                'transport_allowance' => 700.00,
                'other_allowances' => 0.00,
                'total_salary' => 10075.00,
                'bank_name' => 'مصرف الراجحي',
                'iban' => 'SA4480000300000000333333',
                'status' => 'active',
            ],
            [
                'name' => 'فهد عبد الرحمن الدوسري',
                'national_id' => '1062947192',
                'phone' => '0564445566',
                'email' => 'fahad.d@diwan.io',
                'job_title' => 'مسؤول نقطة بيع وكاشير',
                'department' => 'نقاط البيع والتجزئة',
                'branch_id' => $mainBranch->id,
                'hire_date' => Carbon::parse('2025-08-01'),
                'basic_salary' => 5000.00,
                'housing_allowance' => 1250.00,
                'transport_allowance' => 500.00,
                'other_allowances' => 0.00,
                'total_salary' => 6750.00,
                'bank_name' => 'بنك الرياض',
                'iban' => 'SA5520000400000000444444',
                'status' => 'active',
            ],
            [
                'name' => 'ريم سلطان الشهري',
                'national_id' => '1058291048',
                'phone' => '0595556677',
                'email' => 'reem.s@diwan.io',
                'job_title' => 'أخصائية موارد بشرية وعلاقات موظفين',
                'department' => 'الموارد البشرية',
                'branch_id' => $mainBranch->id,
                'hire_date' => Carbon::parse('2025-10-10'),
                'basic_salary' => 6500.00,
                'housing_allowance' => 1625.00,
                'transport_allowance' => 600.00,
                'other_allowances' => 0.00,
                'total_salary' => 8725.00,
                'bank_name' => 'مصرف الإنماء',
                'iban' => 'SA0500000500000000555555',
                'status' => 'active',
            ],
        ];

        $employeeModels = [];
        foreach ($employeesData as $emp) {
            $created = Employee::firstOrCreate(
                ['company_id' => $company->id, 'national_id' => $emp['national_id']],
                $emp
            );
            $employeeModels[] = $created;
        }

        // 3. مسير رواتب سبتمبر 2026 (Payroll)
        $totalBasic = collect($employeeModels)->sum('basic_salary');
        $totalAllowances = collect($employeeModels)->sum(fn ($e) => $e->housing_allowance + $e->transport_allowance + $e->other_allowances);
        $netTotal = $totalBasic + $totalAllowances;

        $payroll = Payroll::firstOrCreate(
            ['company_id' => $company->id, 'payroll_number' => 'PAY-26-000001'],
            [
                'month' => 9,
                'year' => 2026,
                'total_basic' => $totalBasic,
                'total_allowances' => $totalAllowances,
                'total_deductions' => 0.00,
                'net_total' => $netTotal,
                'status' => 'approved',
                'payment_date' => Carbon::parse('2026-09-27'),
                'notes' => 'مسير رواتب موظفي المنشأة لشهر سبتمبر 2026 معتمد للصرف بنظام حماية الأجور WPS.',
                'created_by' => $adminUser?->id,
            ]
        );

        if ($payroll->wasRecentlyCreated || $payroll->items()->count() === 0) {
            foreach ($employeeModels as $emp) {
                PayrollItem::create([
                    'payroll_id' => $payroll->id,
                    'employee_id' => $emp->id,
                    'basic_salary' => $emp->basic_salary,
                    'allowances' => $emp->housing_allowance + $emp->transport_allowance + $emp->other_allowances,
                    'deductions' => 0.00,
                    'net_salary' => $emp->total_salary,
                    'notes' => 'راتب شهر سبتمبر 2026 كاملاً',
                ]);
            }
        }

        // 4. قوالب الطباعة (Print Templates)
        $templates = [
            [
                'template_type' => 'invoice_a4',
                'name' => 'قالب الفاتورة الضريبية القياسي (A4 ZATCA)',
                'primary_color' => '#4F46E5',
                'show_logo' => true,
                'show_qr' => true,
                'show_stamp' => true,
                'show_bank_details' => true,
                'terms_and_conditions' => 'تعتبر هذه الفاتورة ضريبية رسمية وتخضع لأنظمة هيئة الزكاة والضريبة والجمارك بالمملكة العربية السعودية.',
                'footer_text' => 'شكراً لتعاملكم مع مؤسسة أنس التجارية - هاتف: 0114977231',
                'is_default' => true,
            ],
            [
                'template_type' => 'invoice_thermal_80mm',
                'name' => 'قالب إيصال نقاط البيع الحراري (80mm POS)',
                'primary_color' => '#0F172A',
                'show_logo' => true,
                'show_qr' => true,
                'show_stamp' => false,
                'show_bank_details' => false,
                'terms_and_conditions' => 'البضاعة المباعة ترد وتستبدل خلال 7 أيام من تاريخ الشراء بشرط سلامة المنتج والتغليف.',
                'footer_text' => 'نسعد بخدمتكم دائماً!',
                'is_default' => true,
            ],
            [
                'template_type' => 'quotation',
                'name' => 'قالب عروض الأسعار الرسمية (Quotation A4)',
                'primary_color' => '#0284C7',
                'show_logo' => true,
                'show_qr' => false,
                'show_stamp' => true,
                'show_bank_details' => true,
                'terms_and_conditions' => 'عرض السعر ساري لمدة 15 يوماً من تاريخ الإصدار، وتعتبر الأسعار شاملة لضريبة القيمة المضافة 15%.',
                'footer_text' => 'يسعدنا تواصلكم لأي استفسار عبر sales@diwan.io',
                'is_default' => true,
            ],
        ];

        foreach ($templates as $tmpl) {
            PrintTemplate::firstOrCreate(
                ['company_id' => $company->id, 'name' => $tmpl['name']],
                $tmpl
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Revenue;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class AccountingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();
        if (!$company) {
            return;
        }

        $adminUser = User::where('company_id', $company->id)->first() ?? User::first();

        // 1. شجرة الحسابات (Saudi Chart of Accounts)
        $accountsData = [
            // Level 1: الأصول
            ['code' => '1', 'name_ar' => 'الأصول', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => null],
            ['code' => '11', 'name_ar' => 'الأصول المتداولة', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '1'],
            ['code' => '111', 'name_ar' => 'النقدية وما في حكمها', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '11'],
            ['code' => '1111', 'name_ar' => 'الصندوق الرئيسي', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 22350.00, 'parent' => '111'],
            ['code' => '1112', 'name_ar' => 'حساب مصرف الراجحي الجاري', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 185450.00, 'parent' => '111'],
            ['code' => '1113', 'name_ar' => 'حساب البنك الأهلي السعودي SNB', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 98200.00, 'parent' => '111'],
            ['code' => '112', 'name_ar' => 'الذمم المدينة والعملاء', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '11'],
            ['code' => '1121', 'name_ar' => 'حسابات العملاء التجاريين', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 45800.00, 'parent' => '112'],
            ['code' => '113', 'name_ar' => 'المخزون', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '11'],
            ['code' => '1131', 'name_ar' => 'مخزون بضائع المستودع الرئيسي', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 124500.00, 'parent' => '113'],
            ['code' => '114', 'name_ar' => 'ضريبة القيمة المضافة المدخلة (المشتريات)', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 6750.00, 'parent' => '11'],
            ['code' => '12', 'name_ar' => 'الأصول غير المتداولة (الثابتة)', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '1'],
            ['code' => '121', 'name_ar' => 'الأجهزة والمعدات الإلكترونية', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 35000.00, 'parent' => '12'],
            ['code' => '122', 'name_ar' => 'الأثاث والتجهيزات المكتبية', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 20000.00, 'parent' => '12'],

            // Level 1: الخصوم والالتزامات
            ['code' => '2', 'name_ar' => 'الخصوم والالتزامات', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => true, 'parent' => null],
            ['code' => '21', 'name_ar' => 'الالتزامات المتداولة', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => true, 'parent' => '2'],
            ['code' => '211', 'name_ar' => 'الذمم الدائنة والموردين', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => false, 'balance' => 27715.00, 'parent' => '21'],
            ['code' => '212', 'name_ar' => 'ضريبة القيمة المضافة المحصلة (المبيعات)', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => false, 'balance' => 9250.00, 'parent' => '21'],
            ['code' => '213', 'name_ar' => 'مستحقات ورواتب الموظفين', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '21'],

            // Level 1: حقوق الملكية
            ['code' => '3', 'name_ar' => 'حقوق الملكية', 'type' => 'equity', 'nature' => 'credit', 'is_parent' => true, 'parent' => null],
            ['code' => '31', 'name_ar' => 'رأس المال المدفوع', 'type' => 'equity', 'nature' => 'credit', 'is_parent' => false, 'balance' => 450000.00, 'parent' => '3'],
            ['code' => '32', 'name_ar' => 'الأرباح المحتجزة / المبقاة', 'type' => 'equity', 'nature' => 'credit', 'is_parent' => false, 'balance' => 51085.00, 'parent' => '3'],

            // Level 1: الإيرادات
            ['code' => '4', 'name_ar' => 'الإيرادات', 'type' => 'revenue', 'nature' => 'credit', 'is_parent' => true, 'parent' => null],
            ['code' => '41', 'name_ar' => 'إيرادات المبيعات الرئيسية', 'type' => 'revenue', 'nature' => 'credit', 'is_parent' => false, 'balance' => 165000.00, 'parent' => '4'],
            ['code' => '42', 'name_ar' => 'إيرادات الخدمات والاستشارات التقنية', 'type' => 'revenue', 'nature' => 'credit', 'is_parent' => false, 'balance' => 21775.00, 'parent' => '4'],

            // Level 1: المصروفات
            ['code' => '5', 'name_ar' => 'المصروفات', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => true, 'parent' => null],
            ['code' => '51', 'name_ar' => 'تكلفة البضاعة المباعة (COGS)', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 95000.00, 'parent' => '5'],
            ['code' => '52', 'name_ar' => 'المصروفات التشغيلية والإدارية', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => true, 'parent' => '5'],
            ['code' => '521', 'name_ar' => 'إيجار المقر والمستودعات', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 30000.00, 'parent' => '52'],
            ['code' => '522', 'name_ar' => 'رواتب وأجور البدلات', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 45000.00, 'parent' => '52'],
            ['code' => '523', 'name_ar' => 'فواتير الاتصالات والإنترنت', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 1200.00, 'parent' => '52'],
            ['code' => '524', 'name_ar' => 'صيانة وتشغيل ودعم فني', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 2400.00, 'parent' => '52'],
            ['code' => '525', 'name_ar' => 'ضيافة ونظافة ومطبوعات', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 850.00, 'parent' => '52'],
        ];

        $accountModelMap = [];

        foreach ($accountsData as $acc) {
            $parentId = null;
            if ($acc['parent'] && isset($accountModelMap[$acc['parent']])) {
                $parentId = $accountModelMap[$acc['parent']]->id;
            }

            $created = Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $acc['code']],
                [
                    'parent_id' => $parentId,
                    'name_ar' => $acc['name_ar'],
                    'type' => $acc['type'],
                    'nature' => $acc['nature'],
                    'is_parent' => $acc['is_parent'],
                    'balance' => $acc['balance'] ?? 0.00,
                    'is_active' => true,
                ]
            );

            $accountModelMap[$acc['code']] = $created;
        }

        // 2. الحسابات البنكية والخزائن
        $bankRajhi = BankAccount::firstOrCreate(
            ['company_id' => $company->id, 'account_number' => '2048593819001'],
            [
                'account_id' => $accountModelMap['1112']->id ?? null,
                'name' => 'مصرف الراجحي - الحساب الجاري التجاري',
                'type' => 'bank',
                'bank_name' => 'مصرف الراجحي',
                'iban' => 'SA4480000204859381900100',
                'currency' => 'SAR',
                'opening_balance' => 150000.00,
                'current_balance' => 185450.00,
                'is_active' => true,
            ]
        );

        $bankSNB = BankAccount::firstOrCreate(
            ['company_id' => $company->id, 'account_number' => '1029485728001'],
            [
                'account_id' => $accountModelMap['1113']->id ?? null,
                'name' => 'البنك الأهلي السعودي (SNB) - عمليات الشركات',
                'type' => 'bank',
                'bank_name' => 'البنك الأهلي السعودي',
                'iban' => 'SA2210000102948572800100',
                'currency' => 'SAR',
                'opening_balance' => 85000.00,
                'current_balance' => 98200.00,
                'is_active' => true,
            ]
        );

        $cashMain = BankAccount::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'خزينة الفرع الرئيسي (نقدية)'],
            [
                'account_id' => $accountModelMap['1111']->id ?? null,
                'type' => 'cash',
                'currency' => 'SAR',
                'opening_balance' => 15000.00,
                'current_balance' => 22350.00,
                'is_active' => true,
            ]
        );

        // 3. المصروفات التشغيلية
        $expenses = [
            [
                'expense_number' => 'EXP-26-000001',
                'account_id' => $accountModelMap['521']->id ?? null,
                'bank_account_id' => $bankRajhi->id,
                'title' => 'سداد إيجار المقر والمستودع للربع الثالث 2026',
                'category' => 'إيجار',
                'amount' => 30000.00,
                'tax_amount' => 4500.00,
                'total_amount' => 34500.00,
                'expense_date' => Carbon::parse('2026-09-01'),
                'payment_method' => 'bank_transfer',
                'reference_number' => 'RENT-Q3-2026',
                'vendor_name' => 'شركة أصول العقارية المحدودة',
                'notes' => 'سداد دفعة إيجار الربع الثالث بشيك بنكي مصدق.',
            ],
            [
                'expense_number' => 'EXP-26-000002',
                'account_id' => $accountModelMap['523']->id ?? null,
                'bank_account_id' => $bankRajhi->id,
                'title' => 'فاتورة الإنترنت فائق السرعة وخدمات الاتصالات',
                'category' => 'فواتير واتصالات',
                'amount' => 1200.00,
                'tax_amount' => 180.00,
                'total_amount' => 1380.00,
                'expense_date' => Carbon::parse('2026-09-05'),
                'payment_method' => 'card',
                'reference_number' => 'STC-SEP-9812',
                'vendor_name' => 'شركة الاتصالات السعودية (stc)',
                'notes' => 'فاتورة الإنترنت للأعمال لشهر سبتمبر.',
            ],
            [
                'expense_number' => 'EXP-26-000003',
                'account_id' => $accountModelMap['525']->id ?? null,
                'bank_account_id' => $cashMain->id,
                'title' => 'مستلزمات ضيافة ونظافة ومطبوعات مكتبية',
                'category' => 'ضيافة ونظافة',
                'amount' => 850.00,
                'tax_amount' => 127.50,
                'total_amount' => 977.50,
                'expense_date' => Carbon::parse('2026-09-10'),
                'payment_method' => 'cash',
                'reference_number' => 'CASH-HOSP-09',
                'vendor_name' => 'أسواق التميمي',
                'notes' => 'شراء مستلزمات المكتب والضيافة نقداً من الخزينة.',
            ],
            [
                'expense_number' => 'EXP-26-000004',
                'account_id' => $accountModelMap['524']->id ?? null,
                'bank_account_id' => $bankSNB->id,
                'title' => 'صيانة دورية للأجهزة ونظام التكييف والشبكات',
                'category' => 'صيانة وتشغيل',
                'amount' => 2400.00,
                'tax_amount' => 360.00,
                'total_amount' => 2760.00,
                'expense_date' => Carbon::parse('2026-09-15'),
                'payment_method' => 'bank_transfer',
                'reference_number' => 'MAINT-SRV-441',
                'vendor_name' => 'مؤسسة صيانة المستقبل',
                'notes' => 'صيانة شبكات السيرفرات والتكييف المركزي.',
            ],
        ];

        foreach ($expenses as $exp) {
            Expense::firstOrCreate(
                ['company_id' => $company->id, 'expense_number' => $exp['expense_number']],
                array_merge($exp, ['created_by' => $adminUser?->id])
            );
        }

        // 4. الإيرادات الإضافية والمتنوعة
        $revenues = [
            [
                'revenue_number' => 'REV-26-000001',
                'account_id' => $accountModelMap['42']->id ?? null,
                'bank_account_id' => $bankRajhi->id,
                'title' => 'تقديم استشارات تقنية وحلول البنية السحابية',
                'category' => 'استشارات تقنية',
                'amount' => 12000.00,
                'tax_amount' => 1800.00,
                'total_amount' => 13800.00,
                'revenue_date' => Carbon::parse('2026-09-08'),
                'payment_method' => 'bank_transfer',
                'reference_number' => 'CONS-SAAS-01',
                'customer_name' => 'شركة المسار الرقمي القابضة',
                'notes' => 'عقد استشارات تقنية وتهيئة الخوادم السحابية.',
            ],
            [
                'revenue_number' => 'REV-26-000002',
                'account_id' => $accountModelMap['42']->id ?? null,
                'bank_account_id' => $bankSNB->id,
                'title' => 'عقد صيانة ودعم فني برمجيات أنظمة سنوي',
                'category' => 'دعم فني وصيانة',
                'amount' => 8500.00,
                'tax_amount' => 1275.00,
                'total_amount' => 9775.00,
                'revenue_date' => Carbon::parse('2026-09-14'),
                'payment_method' => 'bank_transfer',
                'reference_number' => 'SUPP-ANN-2026',
                'customer_name' => 'مؤسسة الرواد التجارية',
                'notes' => 'دفعة مقدمة لعقد الدعم الفني السنوي.',
            ],
        ];

        foreach ($revenues as $rev) {
            Revenue::firstOrCreate(
                ['company_id' => $company->id, 'revenue_number' => $rev['revenue_number']],
                array_merge($rev, ['created_by' => $adminUser?->id])
            );
        }

        // 5. القيود اليومية (Journal Entries)
        $jv1 = JournalEntry::firstOrCreate(
            ['company_id' => $company->id, 'entry_number' => 'JV-26-000001'],
            [
                'date' => Carbon::parse('2026-01-01'),
                'reference' => 'INIT-CAPITAL-2026',
                'description' => 'قيد افتتاح الأرصدة وإثبات رأس المال التأسيسي للمنشأة',
                'total_debit' => 250000.00,
                'total_credit' => 250000.00,
                'status' => 'posted',
                'created_by' => $adminUser?->id,
            ]
        );

        if ($jv1->wasRecentlyCreated || $jv1->lines()->count() === 0) {
            JournalEntryLine::create(['journal_entry_id' => $jv1->id, 'account_id' => $accountModelMap['1112']->id, 'description' => 'إيداع رأس المال بمصرف الراجحي', 'debit' => 150000.00, 'credit' => 0.00]);
            JournalEntryLine::create(['journal_entry_id' => $jv1->id, 'account_id' => $accountModelMap['1113']->id, 'description' => 'إيداع بالبنك الأهلي السعودي', 'debit' => 85000.00, 'credit' => 0.00]);
            JournalEntryLine::create(['journal_entry_id' => $jv1->id, 'account_id' => $accountModelMap['1111']->id, 'description' => 'عهدة الصندوق الرئيسي النقدية', 'debit' => 15000.00, 'credit' => 0.00]);
            JournalEntryLine::create(['journal_entry_id' => $jv1->id, 'account_id' => $accountModelMap['31']->id, 'description' => 'رأس مال المؤسس المكتتب به', 'debit' => 0.00, 'credit' => 250000.00]);
        }

        $jv2 = JournalEntry::firstOrCreate(
            ['company_id' => $company->id, 'entry_number' => 'JV-26-000002'],
            [
                'date' => Carbon::parse('2026-09-01'),
                'reference' => 'RENT-Q3-2026',
                'description' => 'إثبات مصروف إيجار الربع الثالث مع ضريبة القيمة المضافة',
                'total_debit' => 34500.00,
                'total_credit' => 34500.00,
                'status' => 'posted',
                'created_by' => $adminUser?->id,
            ]
        );

        if ($jv2->wasRecentlyCreated || $jv2->lines()->count() === 0) {
            JournalEntryLine::create(['journal_entry_id' => $jv2->id, 'account_id' => $accountModelMap['521']->id, 'description' => 'مصروف إيجار المقر والمستودع', 'debit' => 30000.00, 'credit' => 0.00]);
            JournalEntryLine::create(['journal_entry_id' => $jv2->id, 'account_id' => $accountModelMap['114']->id, 'description' => 'ضريبة القيمة المضافة المدخلة 15%', 'debit' => 4500.00, 'credit' => 0.00]);
            JournalEntryLine::create(['journal_entry_id' => $jv2->id, 'account_id' => $accountModelMap['1112']->id, 'description' => 'سداد بحوالة من مصرف الراجحي', 'debit' => 0.00, 'credit' => 34500.00]);
        }

        $jv3 = JournalEntry::firstOrCreate(
            ['company_id' => $company->id, 'entry_number' => 'JV-26-000003'],
            [
                'date' => Carbon::parse('2026-09-08'),
                'reference' => 'CONS-SAAS-01',
                'description' => 'إثبات إيرادات استشارات تقنية وحلول سحابية مع الضريبة المحصلة',
                'total_debit' => 13800.00,
                'total_credit' => 13800.00,
                'status' => 'posted',
                'created_by' => $adminUser?->id,
            ]
        );

        if ($jv3->wasRecentlyCreated || $jv3->lines()->count() === 0) {
            JournalEntryLine::create(['journal_entry_id' => $jv3->id, 'account_id' => $accountModelMap['1112']->id, 'description' => 'استلام الحوالة بحساب مصرف الراجحي', 'debit' => 13800.00, 'credit' => 0.00]);
            JournalEntryLine::create(['journal_entry_id' => $jv3->id, 'account_id' => $accountModelMap['42']->id, 'description' => 'إيرادات استشارات تقنية', 'debit' => 0.00, 'credit' => 12000.00]);
            JournalEntryLine::create(['journal_entry_id' => $jv3->id, 'account_id' => $accountModelMap['212']->id, 'description' => 'ضريبة القيمة المضافة المستحقة 15%', 'debit' => 0.00, 'credit' => 1800.00]);
        }
    }
}

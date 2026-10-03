<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Illuminate\Database\Seeder;

class AccountingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();
        if (! $company) {
            return;
        }

        $adminUser = User::where('company_id', $company->id)->first() ?? User::first();

        // 1. شجرة الحسابات (Saudi Chart of Accounts)
        $accountsData = [
            // Level 1: الأصول
            ['code' => '1', 'name_ar' => 'الأصول', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => null],
            ['code' => '11', 'name_ar' => 'الأصول المتداولة', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '1'],
            ['code' => '111', 'name_ar' => 'النقدية وما في حكمها', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '11'],
            ['code' => '1111', 'name_ar' => 'الصندوق الرئيسي', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '111'],
            ['code' => '1112', 'name_ar' => 'حساب الانماء', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '111'],
            ['code' => '1113', 'name_ar' => 'حساب البنك الأهلي السعودي SNB', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '111'],
            ['code' => '112', 'name_ar' => 'الذمم المدينة والعملاء', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '11'],
            ['code' => '1121', 'name_ar' => 'حسابات العملاء التجاريين', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '112'],
            ['code' => '113', 'name_ar' => 'المخزون', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '11'],
            ['code' => '1131', 'name_ar' => 'مخزون بضائع المستودع الرئيسي', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '113'],
            ['code' => '114', 'name_ar' => 'ضريبة القيمة المضافة المدخلة (المشتريات)', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '11'],
            ['code' => '12', 'name_ar' => 'الأصول غير المتداولة (الثابتة)', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => true, 'parent' => '1'],
            ['code' => '121', 'name_ar' => 'الأجهزة والمعدات الإلكترونية', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '12'],
            ['code' => '122', 'name_ar' => 'الأثاث والتجهيزات المكتبية', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '12'],

            // Level 1: الخصوم والالتزامات
            ['code' => '2', 'name_ar' => 'الخصوم والالتزامات', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => true, 'parent' => null],
            ['code' => '21', 'name_ar' => 'الالتزامات المتداولة', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => true, 'parent' => '2'],
            ['code' => '211', 'name_ar' => 'الذمم الدائنة والموردين', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '21'],
            ['code' => '212', 'name_ar' => 'ضريبة القيمة المضافة المحصلة (المبيعات)', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '21'],
            ['code' => '213', 'name_ar' => 'مستحقات ورواتب الموظفين', 'type' => 'liability', 'nature' => 'credit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '21'],

            // Level 1: حقوق الملكية
            ['code' => '3', 'name_ar' => 'حقوق الملكية', 'type' => 'equity', 'nature' => 'credit', 'is_parent' => true, 'parent' => null],
            ['code' => '31', 'name_ar' => 'رأس المال المدفوع', 'type' => 'equity', 'nature' => 'credit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '3'],
            ['code' => '32', 'name_ar' => 'الأرباح المحتجزة / المبقاة', 'type' => 'equity', 'nature' => 'credit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '3'],

            // Level 1: الإيرادات
            ['code' => '4', 'name_ar' => 'الإيرادات', 'type' => 'revenue', 'nature' => 'credit', 'is_parent' => true, 'parent' => null],
            ['code' => '41', 'name_ar' => 'إيرادات المبيعات الرئيسية', 'type' => 'revenue', 'nature' => 'credit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '4'],
            ['code' => '42', 'name_ar' => 'إيرادات الخدمات والاستشارات التقنية', 'type' => 'revenue', 'nature' => 'credit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '4'],

            // Level 1: المصروفات
            ['code' => '5', 'name_ar' => 'المصروفات', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => true, 'parent' => null],
            ['code' => '51', 'name_ar' => 'تكلفة البضاعة المباعة (COGS)', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '5'],
            ['code' => '52', 'name_ar' => 'المصروفات التشغيلية والإدارية', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => true, 'parent' => '5'],
            ['code' => '521', 'name_ar' => 'إيجار المقر والمستودعات', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '52'],
            ['code' => '522', 'name_ar' => 'رواتب وأجور البدلات', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '52'],
            ['code' => '523', 'name_ar' => 'فواتير الاتصالات والإنترنت', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '52'],
            ['code' => '524', 'name_ar' => 'صيانة وتشغيل ودعم فني', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '52'],
            ['code' => '525', 'name_ar' => 'ضيافة ونظافة ومطبوعات', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0.00, 'parent' => '52'],
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
                'name' => 'حساب الانماء الجاري',
                'type' => 'bank',
                'bank_name' => 'مصرف الانماء',
                'iban' => 'SA4480000204859381900100',
                'currency' => 'SAR',
                'opening_balance' => 0.00,
                'current_balance' => 0.00,
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
                'opening_balance' => 0.00,
                'current_balance' => 0.00,
                'is_active' => true,
            ]
        );

        $cashMain = BankAccount::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'خزينة الفرع الرئيسي (نقدية)'],
            [
                'account_id' => $accountModelMap['1111']->id ?? null,
                'type' => 'cash',
                'currency' => 'SAR',
                'opening_balance' => 0.00,
                'current_balance' => 0.00,
                'is_active' => true,
            ]
        );

        // 3. مزامنة القيود والحسابات من الفواتير الفعلية المنشأة في النظام
        $invoices = Invoice::where('company_id', $company->id)->with('customer')->orderBy('id')->get();

        $totalCash = 0;
        $totalBank = 0;
        $totalSales = 0;
        $totalTax = 0;

        $entryIndex = 1;
        foreach ($invoices as $inv) {
            $entryNumber = 'JV-26-'.str_pad($entryIndex, 6, '0', STR_PAD_LEFT);
            $entryIndex++;

            $customerName = $inv->customer ? ($inv->customer->name ?? $inv->customer->company_name) : 'عميل عام';
            $desc = "فاتورة مبيعات رقم {$inv->invoice_number} - {$customerName}";

            $isCash = ($inv->payment_method === 'cash');
            $debitAccountId = $isCash ? $accountModelMap['1111']->id : $accountModelMap['1112']->id;

            $entry = JournalEntry::firstOrCreate(
                ['company_id' => $company->id, 'entry_number' => $entryNumber],
                [
                    'branch_id' => $inv->branch_id,
                    'date' => $inv->invoice_date ?? $inv->created_at,
                    'reference' => $inv->invoice_number,
                    'description' => $desc,
                    'total_debit' => $inv->total,
                    'total_credit' => $inv->total,
                    'status' => 'posted',
                    'created_by' => $inv->user_id ?? $adminUser?->id,
                ]
            );

            if ($entry->wasRecentlyCreated || $entry->lines()->count() === 0) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $debitAccountId,
                    'description' => ($isCash ? 'تحصيل مبيعات نقداً' : 'تحصيل مبيعات بحوالة بنكية')." - فاتورة {$inv->invoice_number}",
                    'debit' => $inv->total,
                    'credit' => 0.00,
                ]);

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $accountModelMap['41']->id,
                    'description' => "إيراد مبيعات فاتورة {$inv->invoice_number}",
                    'debit' => 0.00,
                    'credit' => $inv->subtotal,
                ]);

                if ($inv->tax_amount > 0) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $accountModelMap['212']->id,
                        'description' => "ضريبة القيمة المضافة المحصلة (15%) - فاتورة {$inv->invoice_number}",
                        'debit' => 0.00,
                        'credit' => $inv->tax_amount,
                    ]);
                }
            }

            if ($isCash) {
                $totalCash += (float) $inv->total;
            } else {
                $totalBank += (float) $inv->total;
            }
            $totalSales += (float) $inv->subtotal;
            $totalTax += (float) $inv->tax_amount;
        }

        // تحديث الأرصدة الفعلية في شجرة الحسابات
        if (isset($accountModelMap['1111'])) {
            $accountModelMap['1111']->update(['balance' => $totalCash]);
        }
        if (isset($accountModelMap['1112'])) {
            $accountModelMap['1112']->update(['balance' => $totalBank]);
        }
        if (isset($accountModelMap['41'])) {
            $accountModelMap['41']->update(['balance' => $totalSales]);
        }
        if (isset($accountModelMap['212'])) {
            $accountModelMap['212']->update(['balance' => $totalTax]);
        }

        // تحديث أرصدة الخزينة والحساب البنكي
        $bankRajhi->update(['opening_balance' => 0.00, 'current_balance' => $totalBank]);
        $cashMain->update(['opening_balance' => 0.00, 'current_balance' => $totalCash]);
        $bankSNB->update(['opening_balance' => 0.00, 'current_balance' => 0.00]);
    }
}

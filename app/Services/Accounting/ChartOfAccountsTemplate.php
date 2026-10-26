<?php

namespace App\Services\Accounting;

/**
 * Default Saudi chart of accounts (SOCPA / IFRS for SMEs friendly) provisioned
 * for every new company. Each leaf that the system posts to automatically is
 * tagged with a "role" (see AccountMap::ROLES).
 *
 * Row: [code, parent code, name ar, name en, type, nature, is_parent, role, cash flow category]
 */
class ChartOfAccountsTemplate
{
    public static function rows(): array
    {
        return [
            // 1 Assets
            ['1', null, 'الأصول', 'Assets', 'asset', 'debit', true, null, null],
            ['11', '1', 'الأصول المتداولة', 'Current Assets', 'asset', 'debit', true, null, null],
            ['111', '11', 'النقدية وما في حكمها', 'Cash & Cash Equivalents', 'asset', 'debit', true, null, null],
            ['1111', '111', 'الصندوق الرئيسي', 'Main Cash Box', 'asset', 'debit', false, 'default_cash', 'cash'],
            ['1112', '111', 'البنك الرئيسي', 'Main Bank Account', 'asset', 'debit', false, 'default_bank', 'cash'],
            ['1113', '111', 'العهد النقدية', 'Petty Cash / Custody', 'asset', 'debit', false, 'petty_cash', 'cash'],
            ['112', '11', 'الذمم المدينة', 'Receivables', 'asset', 'debit', true, null, null],
            ['1121', '112', 'العملاء', 'Accounts Receivable', 'asset', 'debit', false, 'receivables', 'operating'],
            ['1122', '112', 'شيكات تحت التحصيل', 'Cheques Under Collection', 'asset', 'debit', false, 'cheques_under_collection', 'operating'],
            ['1123', '112', 'سلف وعهد الموظفين', 'Employee Advances', 'asset', 'debit', false, 'employee_advances', 'operating'],
            ['1124', '112', 'محتجزات لدى العملاء', 'Retention Receivable', 'asset', 'debit', false, 'retention_receivable', 'operating'],
            ['1125', '112', 'دفعات مقدمة للموردين', 'Supplier Advances', 'asset', 'debit', false, 'supplier_advances', 'operating'],
            ['113', '11', 'المخزون', 'Inventory', 'asset', 'debit', true, null, null],
            ['1131', '113', 'مخزون البضاعة', 'Merchandise Inventory', 'asset', 'debit', false, 'inventory', 'operating'],
            ['1132', '113', 'بضاعة في الطريق', 'Goods In Transit', 'asset', 'debit', false, 'goods_in_transit', 'operating'],
            ['114', '11', 'ضريبة القيمة المضافة المدخلة', 'VAT Input', 'asset', 'debit', false, 'vat_input', 'operating'],
            ['115', '11', 'مصروفات مدفوعة مقدماً', 'Prepaid Expenses', 'asset', 'debit', false, 'prepaid_expenses', 'operating'],
            ['12', '1', 'الأصول غير المتداولة', 'Non-current Assets', 'asset', 'debit', true, null, null],
            ['121', '12', 'الأصول الثابتة', 'Property, Plant & Equipment', 'asset', 'debit', true, null, null],
            ['1211', '121', 'الأراضي والمباني', 'Land & Buildings', 'asset', 'debit', false, null, 'investing'],
            ['1212', '121', 'الآلات والمعدات', 'Machinery & Equipment', 'asset', 'debit', false, 'fixed_assets', 'investing'],
            ['1213', '121', 'السيارات ووسائل النقل', 'Vehicles', 'asset', 'debit', false, null, 'investing'],
            ['1214', '121', 'الأثاث والتجهيزات', 'Furniture & Fixtures', 'asset', 'debit', false, null, 'investing'],
            ['1215', '121', 'أجهزة الحاسب', 'Computers', 'asset', 'debit', false, null, 'investing'],
            ['122', '12', 'مجمع الإهلاك', 'Accumulated Depreciation', 'asset', 'credit', true, null, null],
            ['1221', '122', 'مجمع إهلاك الأصول الثابتة', 'Accumulated Depreciation - PPE', 'asset', 'credit', false, 'accumulated_depreciation', 'investing'],

            // 2 Liabilities
            ['2', null, 'الخصوم', 'Liabilities', 'liability', 'credit', true, null, null],
            ['21', '2', 'الخصوم المتداولة', 'Current Liabilities', 'liability', 'credit', true, null, null],
            ['211', '21', 'الموردون', 'Accounts Payable', 'liability', 'credit', false, 'payables', 'operating'],
            ['2110', '21', 'بضاعة مستلمة لم تصل فواتيرها', 'Goods Received Not Invoiced', 'liability', 'credit', false, 'grni', 'operating'],
            ['2112', '21', 'مخصص الإجازات السنوية', 'Annual Leave Provision', 'liability', 'credit', false, 'leave_provision', 'operating'],
            ['2111', '21', 'صندوق جزاءات العمال', 'Workers Fund (Penalties)', 'liability', 'credit', false, 'workers_fund', 'operating'],
            ['212', '21', 'ضريبة القيمة المضافة المخرجة', 'VAT Output', 'liability', 'credit', false, 'vat_output', 'operating'],
            ['213', '21', 'رواتب مستحقة', 'Salaries Payable', 'liability', 'credit', false, 'salaries_payable', 'operating'],
            ['214', '21', 'التأمينات الاجتماعية المستحقة', 'GOSI Payable', 'liability', 'credit', false, 'gosi_payable', 'operating'],
            ['215', '21', 'شيكات مؤجلة الدفع', 'Cheques Payable', 'liability', 'credit', false, 'cheques_payable', 'operating'],
            ['216', '21', 'دفعات مقدمة من العملاء', 'Customer Advances', 'liability', 'credit', false, 'customer_advances', 'operating'],
            ['217', '21', 'محتجزات مقاولي الباطن', 'Retention Payable', 'liability', 'credit', false, 'retention_payable', 'operating'],
            ['218', '21', 'مصروفات مستحقة', 'Accrued Expenses', 'liability', 'credit', false, 'accrued_expenses', 'operating'],
            ['219', '21', 'الزكاة المستحقة', 'Zakat Payable', 'liability', 'credit', false, 'zakat_payable', 'operating'],
            ['22', '2', 'الخصوم غير المتداولة', 'Non-current Liabilities', 'liability', 'credit', true, null, null],
            ['221', '22', 'مخصص مكافأة نهاية الخدمة', 'End of Service Provision', 'liability', 'credit', false, 'end_of_service_provision', 'operating'],
            ['222', '22', 'قروض طويلة الأجل', 'Long-term Loans', 'liability', 'credit', false, 'long_term_loans', 'financing'],

            // 3 Equity
            ['3', null, 'حقوق الملكية', 'Equity', 'equity', 'credit', true, null, null],
            ['31', '3', 'رأس المال', 'Capital', 'equity', 'credit', false, 'capital', 'financing'],
            ['32', '3', 'الأرباح المحتجزة', 'Retained Earnings', 'equity', 'credit', false, 'retained_earnings', 'financing'],
            ['33', '3', 'جاري الشركاء', 'Partners Current Account', 'equity', 'credit', false, 'partners_current', 'financing'],
            ['34', '3', 'أرصدة افتتاحية', 'Opening Balance Equity', 'equity', 'credit', false, 'opening_balance_equity', 'financing'],

            // 4 Revenue
            ['4', null, 'الإيرادات', 'Revenue', 'revenue', 'credit', true, null, null],
            ['41', '4', 'إيرادات المبيعات', 'Sales Revenue', 'revenue', 'credit', false, 'sales_revenue', 'operating'],
            ['42', '4', 'إيرادات الخدمات', 'Service Revenue', 'revenue', 'credit', false, 'service_revenue', 'operating'],
            ['43', '4', 'إيرادات المشاريع والعقود', 'Contract Revenue', 'revenue', 'credit', false, 'contract_revenue', 'operating'],
            ['44', '4', 'مردودات ومسموحات المبيعات', 'Sales Returns & Allowances', 'revenue', 'debit', false, 'sales_returns', 'operating'],
            ['45', '4', 'خصم مسموح به', 'Sales Discounts', 'revenue', 'debit', false, 'sales_discounts', 'operating'],
            ['46', '4', 'إيرادات أخرى', 'Other Income', 'revenue', 'credit', false, 'other_revenue', 'operating'],
            ['47', '4', 'أرباح بيع أصول', 'Gain on Asset Disposal', 'revenue', 'credit', false, 'asset_disposal_gain', 'investing'],
            ['48', '4', 'إيرادات تأجير المعدات', 'Equipment Rental Revenue', 'revenue', 'credit', false, 'rental_revenue', 'operating'],

            // 5 Expenses
            ['5', null, 'المصروفات', 'Expenses', 'expense', 'debit', true, null, null],
            ['51', '5', 'تكلفة المبيعات', 'Cost of Sales', 'expense', 'debit', true, null, null],
            ['511', '51', 'تكلفة البضاعة المباعة', 'Cost of Goods Sold', 'expense', 'debit', false, 'cogs', 'operating'],
            ['512', '51', 'المشتريات (أصناف غير مخزنية)', 'Purchases (Non-stock)', 'expense', 'debit', false, 'purchases_cogs', 'operating'],
            ['513', '51', 'تسويات وعجز المخزون', 'Inventory Adjustments', 'expense', 'debit', false, 'inventory_adjustment', 'operating'],
            ['514', '51', 'تكاليف المشاريع المباشرة', 'Direct Project Costs', 'expense', 'debit', false, 'project_costs', 'operating'],
            ['515', '51', 'تكاليف مقاولي الباطن', 'Subcontractor Costs', 'expense', 'debit', false, 'subcontractor_costs', 'operating'],
            ['52', '5', 'المصروفات العمومية والإدارية', 'General & Administrative', 'expense', 'debit', true, null, null],
            ['521', '52', 'الرواتب والأجور', 'Salaries & Wages', 'expense', 'debit', false, 'salaries_expense', 'operating'],
            ['522', '52', 'التأمينات الاجتماعية', 'GOSI Expense', 'expense', 'debit', false, 'gosi_expense', 'operating'],
            ['523', '52', 'مكافأة نهاية الخدمة', 'End of Service Expense', 'expense', 'debit', false, 'end_of_service_expense', 'operating'],
            ['524', '52', 'الإيجارات', 'Rent', 'expense', 'debit', false, 'rent_expense', 'operating'],
            ['525', '52', 'الكهرباء والمياه والاتصالات', 'Utilities & Telecom', 'expense', 'debit', false, null, 'operating'],
            ['526', '52', 'الصيانة', 'Maintenance', 'expense', 'debit', false, 'maintenance_expense', 'operating'],
            ['527', '52', 'مصروفات حكومية ورسوم', 'Government Fees', 'expense', 'debit', false, null, 'operating'],
            ['528', '52', 'مصروفات عمومية أخرى', 'Other G&A Expenses', 'expense', 'debit', false, 'general_expense', 'operating'],
            ['529', '52', 'مصروف الإهلاك', 'Depreciation Expense', 'expense', 'debit', false, 'depreciation_expense', 'operating'],
            ['5211', '52', 'مصروف الإجازات السنوية', 'Annual Leave Expense', 'expense', 'debit', false, 'leave_expense', 'operating'],
            ['5210', '52', 'عجز وزيادة الصندوق', 'Cash Over / Short', 'expense', 'debit', false, 'cash_over_short', 'operating'],
            ['5213', '52', 'خسائر هبوط قيمة الأصول', 'Asset Impairment Losses', 'expense', 'debit', false, 'impairment_loss', 'operating'],
            ['53', '5', 'مصروفات البيع والتسويق', 'Selling & Marketing', 'expense', 'debit', false, 'marketing_expense', 'operating'],
            ['54', '5', 'مصروفات تمويلية وبنكية', 'Finance & Bank Charges', 'expense', 'debit', false, 'bank_charges', 'operating'],
            ['55', '5', 'خسائر بيع أصول', 'Loss on Asset Disposal', 'expense', 'debit', false, 'asset_disposal_loss', 'investing'],
            ['56', '5', 'فروقات التقريب', 'Rounding Differences', 'expense', 'debit', false, 'rounding', 'operating'],
            ['57', '5', 'الزكاة', 'Zakat Expense', 'expense', 'debit', false, 'zakat_expense', 'operating'],
            ['58', '5', 'أرباح وخسائر فروق العملة', 'Exchange Gains & Losses', 'expense', 'debit', false, 'fx_difference', 'operating'],
            // Contra-expense: equipment running costs moved into project costs (not revenue).
            ['59', '5', 'تكاليف معدات محمّلة على المشاريع', 'Equipment Costs Charged to Projects', 'expense', 'credit', false, 'equipment_recovery', 'operating'],
        ];
    }
}

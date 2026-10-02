<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CompanySetting;

class AccountingMappingService
{
    /**
     * Get the mapped Account model for a given operational role.
     * Supported roles:
     * - 'sales_revenue': Sales Revenues (إيرادات المبيعات الرئيسية)
     * - 'purchases_cogs': Purchases / Cost of Goods Sold (تكلفة البضاعة المباعة / المشتريات)
     * - 'vat_output': VAT Output Tax (ضريبة القيمة المضافة المحصلة - المبيعات)
     * - 'vat_input': VAT Input Tax (ضريبة القيمة المضافة المدخلة - المشتريات)
     * - 'receivables': Accounts Receivable (الذمم المدينة وحسابات العملاء)
     * - 'payables': Accounts Payable (الذمم الدائنة والموردين)
     * - 'default_cash': Default Cash Box (الصندوق الرئيسي)
     * - 'default_bank': Default Bank Account (حساب البنك الرئيسي)
     */
    public static function getAccount(int $companyId, string $role): ?Account
    {
        $settingKey = 'acc_' . $role . '_id';
        $setting = CompanySetting::where('company_id', $companyId)->where('key', $settingKey)->value('value');

        if (!empty($setting)) {
            $account = Account::where('company_id', $companyId)->find((int) $setting);
            if ($account) {
                return $account;
            }
        }

        // Intelligent fallback: if custom mapping not explicitly saved yet, detect by standard code or name pattern
        return match ($role) {
            'sales_revenue' => Account::where('company_id', $companyId)->where(function ($q) {
                $q->where('code', '41')
                  ->orWhere('code', '4101')
                  ->orWhere('code', '4001')
                  ->orWhere('name_ar', 'like', '%إيراد%مبيعات%')
                  ->orWhere('name_ar', 'like', '%مبيعات%');
            })->first() ?? Account::where('company_id', $companyId)->where('type', 'revenue')->where('is_parent', false)->first(),

            'purchases_cogs' => Account::where('company_id', $companyId)->where(function ($q) {
                $q->where('code', '51')
                  ->orWhere('code', '5101')
                  ->orWhere('code', '5001')
                  ->orWhere('name_ar', 'like', '%تكلفة%بضاعة%')
                  ->orWhere('name_ar', 'like', '%مشتريات%');
            })->first() ?? Account::where('company_id', $companyId)->where('type', 'expense')->where('is_parent', false)->first(),

            'vat_output' => Account::where('company_id', $companyId)->where(function ($q) {
                $q->where('code', '212')
                  ->orWhere('code', '2102')
                  ->orWhere('name_ar', 'like', '%ضريبة%محصلة%')
                  ->orWhere('name_ar', 'like', '%ضريبة%مبيعات%');
            })->first() ?? Account::where('company_id', $companyId)->where('type', 'liability')->where('is_parent', false)->first(),

            'vat_input' => Account::where('company_id', $companyId)->where(function ($q) {
                $q->where('code', '114')
                  ->orWhere('code', '1104')
                  ->orWhere('name_ar', 'like', '%ضريبة%مدخلة%')
                  ->orWhere('name_ar', 'like', '%ضريبة%مشتريات%');
            })->first() ?? Account::where('company_id', $companyId)->where('type', 'asset')->where('is_parent', false)->first(),

            'receivables' => Account::where('company_id', $companyId)->where(function ($q) {
                $q->where('code', '1121')
                  ->orWhere('code', '112')
                  ->orWhere('name_ar', 'like', '%عملاء%')
                  ->orWhere('name_ar', 'like', '%مدينون%');
            })->first() ?? Account::where('company_id', $companyId)->where('type', 'asset')->where('is_parent', false)->first(),

            'payables' => Account::where('company_id', $companyId)->where(function ($q) {
                $q->where('code', '211')
                  ->orWhere('code', '2101')
                  ->orWhere('name_ar', 'like', '%موردين%')
                  ->orWhere('name_ar', 'like', '%دائنون%');
            })->first() ?? Account::where('company_id', $companyId)->where('type', 'liability')->where('is_parent', false)->first(),

            'default_cash' => Account::where('company_id', $companyId)->where(function ($q) {
                $q->where('code', '1111')
                  ->orWhere('code', '1101')
                  ->orWhere('name_ar', 'like', '%صندوق%')
                  ->orWhere('name_ar', 'like', '%خزينة%');
            })->first() ?? Account::where('company_id', $companyId)->where('type', 'asset')->where('is_parent', false)->first(),

            'default_bank' => Account::where('company_id', $companyId)->where(function ($q) {
                $q->where('code', '1112')
                  ->orWhere('code', '1102')
                  ->orWhere('name_ar', 'like', '%مصرف%')
                  ->orWhere('name_ar', 'like', '%بنك%');
            })->first() ?? Account::where('company_id', $companyId)->where('type', 'asset')->where('is_parent', false)->first(),

            default => null,
        };
    }
}

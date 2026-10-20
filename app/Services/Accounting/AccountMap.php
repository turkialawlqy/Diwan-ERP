<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Services\TenantManager;

/**
 * Resolves the accounts the system posts to automatically ("roles") from the
 * company's settings (acc_{role}_id). Mappings are provisioned with the default
 * chart and editable from the accounting settings screen.
 */
class AccountMap
{
    /** role => [label ar, account type it must belong to] */
    public const ROLES = [
        'default_cash' => ['الصندوق الافتراضي', 'asset'],
        'default_bank' => ['البنك الافتراضي', 'asset'],
        'petty_cash' => ['العهد النقدية', 'asset'],
        'receivables' => ['العملاء (الذمم المدينة)', 'asset'],
        'cheques_under_collection' => ['شيكات تحت التحصيل', 'asset'],
        'employee_advances' => ['سلف الموظفين', 'asset'],
        'retention_receivable' => ['محتجزات لدى العملاء', 'asset'],
        'supplier_advances' => ['دفعات مقدمة للموردين', 'asset'],
        'inventory' => ['المخزون', 'asset'],
        'goods_in_transit' => ['بضاعة في الطريق', 'asset'],
        'vat_input' => ['ضريبة المدخلات', 'asset'],
        'prepaid_expenses' => ['مصروفات مدفوعة مقدماً', 'asset'],
        'fixed_assets' => ['الأصول الثابتة (افتراضي)', 'asset'],
        'accumulated_depreciation' => ['مجمع الإهلاك', 'asset'],
        'payables' => ['الموردون (الذمم الدائنة)', 'liability'],
        'grni' => ['بضاعة مستلمة لم تفوتر', 'liability'],
        'vat_output' => ['ضريبة المخرجات', 'liability'],
        'salaries_payable' => ['رواتب مستحقة', 'liability'],
        'gosi_payable' => ['التأمينات المستحقة', 'liability'],
        'cheques_payable' => ['شيكات مؤجلة الدفع', 'liability'],
        'customer_advances' => ['دفعات مقدمة من العملاء', 'liability'],
        'retention_payable' => ['محتجزات مقاولي الباطن', 'liability'],
        'accrued_expenses' => ['مصروفات مستحقة', 'liability'],
        'workers_fund' => ['صندوق جزاءات العمال', 'liability'],
        'zakat_payable' => ['زكاة مستحقة', 'liability'],
        'end_of_service_provision' => ['مخصص نهاية الخدمة', 'liability'],
        'leave_provision' => ['مخصص الإجازات السنوية', 'liability'],
        'long_term_loans' => ['قروض طويلة الأجل', 'liability'],
        'capital' => ['رأس المال', 'equity'],
        'retained_earnings' => ['الأرباح المحتجزة', 'equity'],
        'partners_current' => ['جاري الشركاء', 'equity'],
        'opening_balance_equity' => ['أرصدة افتتاحية', 'equity'],
        'sales_revenue' => ['إيرادات المبيعات', 'revenue'],
        'service_revenue' => ['إيرادات الخدمات', 'revenue'],
        'contract_revenue' => ['إيرادات العقود', 'revenue'],
        'sales_returns' => ['مردودات المبيعات', 'revenue'],
        'sales_discounts' => ['خصم مسموح به', 'revenue'],
        'other_revenue' => ['إيرادات أخرى', 'revenue'],
        'asset_disposal_gain' => ['أرباح بيع أصول', 'revenue'],
        'rental_revenue' => ['إيرادات تأجير المعدات', 'revenue'],
        'cogs' => ['تكلفة البضاعة المباعة', 'expense'],
        'purchases_cogs' => ['المشتريات غير المخزنية', 'expense'],
        'inventory_adjustment' => ['تسويات المخزون', 'expense'],
        'project_costs' => ['تكاليف المشاريع', 'expense'],
        'subcontractor_costs' => ['تكاليف مقاولي الباطن', 'expense'],
        'salaries_expense' => ['الرواتب والأجور', 'expense'],
        'gosi_expense' => ['مصروف التأمينات', 'expense'],
        'end_of_service_expense' => ['مصروف نهاية الخدمة', 'expense'],
        'leave_expense' => ['مصروف الإجازات السنوية', 'expense'],
        'rent_expense' => ['الإيجارات', 'expense'],
        'maintenance_expense' => ['مصروفات الصيانة', 'expense'],
        'general_expense' => ['مصروفات عمومية', 'expense'],
        'depreciation_expense' => ['مصروف الإهلاك', 'expense'],
        'impairment_loss' => ['خسائر هبوط قيمة الأصول', 'expense'],
        'marketing_expense' => ['مصروفات التسويق', 'expense'],
        'bank_charges' => ['عمولات بنكية', 'expense'],
        'asset_disposal_loss' => ['خسائر بيع أصول', 'expense'],
        'rounding' => ['فروقات التقريب', 'expense'],
        'cash_over_short' => ['عجز وزيادة الصندوق', 'expense'],
        'fx_difference' => ['أرباح وخسائر فروق العملة', 'expense'],
        'zakat_expense' => ['مصروف الزكاة', 'expense'],
        'equipment_recovery' => ['تكاليف معدات محمّلة على المشاريع', 'expense'],
    ];

    protected array $cache = [];

    public function __construct(protected TenantManager $tenantManager) {}

    /**
     * Account id for a role; throws a user-facing error when not configured.
     */
    public function id(string $role, ?int $companyId = null): int
    {
        $id = $this->find($role, $companyId);

        if (! $id) {
            $label = self::ROLES[$role][0] ?? $role;
            throw new AccountingException("لم يتم ربط حساب «{$label}» في إعدادات المحاسبة. يرجى ضبطه من شاشة ربط الحسابات.");
        }

        return $id;
    }

    public function find(string $role, ?int $companyId = null): ?int
    {
        $companyId ??= $this->tenantManager->getTenantId();
        $key = "{$companyId}:{$role}";

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = CompanySetting::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('key', "acc_{$role}_id")
            ->value('value');

        $id = $value ? Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_parent', false)
            ->whereKey((int) $value)
            ->value('id') : null;

        return $this->cache[$key] = $id ? (int) $id : null;
    }

    public function set(string $role, ?int $accountId, int $companyId): void
    {
        CompanySetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'key' => "acc_{$role}_id"],
            ['group' => 'accounting', 'value' => (string) ($accountId ?? ''), 'type' => 'integer'],
        );

        unset($this->cache["{$companyId}:{$role}"]);
    }

    /**
     * role => account id (or null) for the mapping screen.
     */
    public function all(?int $companyId = null): array
    {
        return collect(array_keys(self::ROLES))
            ->mapWithKeys(fn ($role) => [$role => $this->find($role, $companyId)])
            ->all();
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}

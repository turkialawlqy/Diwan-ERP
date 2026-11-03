<?php

namespace App\Services\WorkOrders;

use App\Services\TenantManager;

/** Company-wide work-order rules (the «إعدادات أوامر العمل» page). */
class WorkOrderSettings
{
    public const DEFAULTS = ['require_checklist' => false, 'require_signature' => false, 'default_warehouse' => null, 'stale_timer_hours' => 16];

    public function all(): array
    {
        $company = app(TenantManager::class)->getCompany();
        $get = fn (string $k) => $company ? $company->setting("work_orders_{$k}", self::DEFAULTS[$k]) : self::DEFAULTS[$k];

        return [
            'require_checklist' => filter_var($get('require_checklist'), FILTER_VALIDATE_BOOLEAN),
            'require_signature' => filter_var($get('require_signature'), FILTER_VALIDATE_BOOLEAN),
            'default_warehouse' => $get('default_warehouse') ? (int) $get('default_warehouse') : null,
            'stale_timer_hours' => max(1, (int) $get('stale_timer_hours')),
        ];
    }

    public function save(array $data): void
    {
        $company = app(TenantManager::class)->getCompany();
        $company->setSetting('work_orders_require_checklist', ! empty($data['require_checklist']) ? '1' : '0', 'work_orders', 'string');
        $company->setSetting('work_orders_require_signature', ! empty($data['require_signature']) ? '1' : '0', 'work_orders', 'string');
        $company->setSetting('work_orders_default_warehouse', (string) ($data['default_warehouse'] ?? ''), 'work_orders', 'string');
        $company->setSetting('work_orders_stale_timer_hours', (string) max(1, (int) ($data['stale_timer_hours'] ?? 16)), 'work_orders', 'string');
    }
}

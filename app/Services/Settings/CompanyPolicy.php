<?php

namespace App\Services\Settings;

use App\Models\Company;
use App\Services\TenantManager;

/**
 * Per-company business rules with the platform defaults (config files) as
 * fallback. Overrides are stored per group in company_settings under
 * "policy.{group}" (JSON for arrays), so each company can follow its own
 * GOSI circulars or contract terms without touching other companies.
 */
class CompanyPolicy
{
    /** Overridable groups (config keys). */
    public const GROUPS = [
        'hr.month_days', 'hr.hours_per_day', 'hr.overtime_premium', 'hr.max_deduction_ratio',
        'hr.gosi', 'hr.annual_leave', 'hr.sick_tiers', 'hr.end_of_service', 'hr.wps', 'hr.late_deduction',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return app(static::class)->value($key, $default);
    }

    public function value(string $key, mixed $default = null, ?Company $company = null): mixed
    {
        $group = $this->groupOf($key);
        if (! $group) {
            return config($key, $default);
        }

        $merged = $this->group($group, $company);
        $rest = substr($key, strlen($group) + 1);

        return $rest === '' || $rest === false ? ($merged ?? $default) : data_get($merged, $rest, $default);
    }

    /** Effective value of a whole group: defaults overlaid by the company's values. */
    public function group(string $group, ?Company $company = null): mixed
    {
        $default = config($group);
        $company ??= app(TenantManager::class)->getCompany();
        $override = $company?->setting('policy.'.$group);

        if ($override === null) {
            return $default;
        }

        return is_array($default) && is_array($override) && ! array_is_list($default)
            ? array_replace($default, $override)
            : $override;
    }

    public function isOverridden(string $group, ?Company $company = null): bool
    {
        $company ??= app(TenantManager::class)->getCompany();

        return $company?->setting('policy.'.$group) !== null;
    }

    public function set(Company $company, string $group, mixed $value): void
    {
        abort_unless(in_array($group, self::GROUPS, true), 422);
        $company->setSetting('policy.'.$group, is_array($value) ? $value : json_encode($value), 'policy', 'json');
    }

    public function reset(Company $company, string $group): void
    {
        // Model events flush the settings cache.
        $company->settings()->where('key', 'policy.'.$group)->get()->each->delete();
    }

    protected function groupOf(string $key): ?string
    {
        foreach (self::GROUPS as $group) {
            if ($key === $group || str_starts_with($key, $group.'.')) {
                return $group;
            }
        }

        return null;
    }
}

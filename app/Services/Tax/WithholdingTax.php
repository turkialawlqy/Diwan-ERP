<?php

namespace App\Services\Tax;

use App\Services\TenantManager;

/** Withholding rates by payment category: the statutory ones from config/wht.php unless the company set its own. */
class WithholdingTax
{
    public static function categories(): array
    {
        return collect(config('wht.categories'))->map(fn ($c, $key) => ['key' => $key, 'label' => $c[0], 'rate' => self::rate($key)])->values()->all();
    }

    public static function rate(?string $category): float
    {
        $category = isset(config('wht.categories')[$category]) ? $category : 'other';
        $company = app(TenantManager::class)->getCompany();
        $custom = $company?->setting("wht_rate_{$category}");

        return $custom !== null && $custom !== '' ? (float) $custom : (float) config("wht.categories.{$category}.1");
    }
}

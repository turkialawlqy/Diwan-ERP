<?php

namespace App\Support;

/**
 * Arabic name, sub-unit (for amounts in words) and short symbol of a
 * currency: the Arab currencies from config/countries.php plus the common
 * foreign ones. Unknown codes fall back to the code itself.
 */
class CurrencyNames
{
    protected const FOREIGN = [
        'USD' => ['دولار أمريكي', 'سنت', '$'],
        'EUR' => ['يورو', 'سنت', '€'],
        'GBP' => ['جنيه إسترليني', 'بنس', '£'],
        'CNY' => ['يوان صيني', 'فين', '¥'],
        'TRY' => ['ليرة تركية', 'قرش', '₺'],
        'INR' => ['روبية هندية', 'بيسة', '₹'],
    ];

    /** @return array{0: string, 1: string, 2: string} [name, fraction, symbol] */
    public static function for(?string $code): array
    {
        $code = strtoupper((string) $code) ?: 'SAR';
        foreach (config('countries') as $country) {
            if ($country['currency'] === $code) {
                return [$country['currency_name'], $country['fraction'], $country['symbol']];
            }
        }

        return self::FOREIGN[$code] ?? [$code, 'جزء من مائة', $code];
    }
}

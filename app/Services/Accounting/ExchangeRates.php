<?php

namespace App\Services\Accounting;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Document currencies. The company's base currency has rate 1; any other
 * currency must be defined in Settings → Currencies and gets the latest dated
 * rate on or before the document date (or the currency's default rate).
 * A rate means: 1 unit of the currency = rate units of base currency.
 */
class ExchangeRates
{
    public function __construct(protected TenantManager $tenants) {}

    public function base(): string
    {
        return strtoupper($this->tenants->getCompany()?->currency ?: 'SAR');
    }

    public function isBase(?string $code): bool
    {
        return ! $code || strtoupper($code) === $this->base();
    }

    public function rate(string $code, $date = null): float
    {
        $code = strtoupper($code);
        if ($this->isBase($code)) {
            return 1.0;
        }

        $dated = ExchangeRate::where('currency_code', $code)
            ->whereDate('date', '<=', Carbon::parse($date ?? now())->toDateString())
            ->orderByDesc('date')->value('rate');
        $rate = (float) ($dated ?? Currency::where('code', $code)->value('exchange_rate'));

        if ($rate <= 0) {
            throw ValidationException::withMessages(['currency_code' => "لا يوجد سعر صرف للعملة {$code}. أضفه من الإعدادات ← العملات."]);
        }

        return $rate;
    }

    /**
     * Currency and rate for a new document from request data: base when none
     * is given, the given rate when the user overrode it, otherwise the rate
     * on the document date.
     *
     * @return array{0: ?string, 1: float} [currency_code (null = base), exchange_rate]
     */
    public function resolve(?string $code, $rate, $date): array
    {
        if ($this->isBase($code)) {
            return [null, 1.0];
        }
        $code = strtoupper($code);
        if (! Currency::where('code', $code)->exists()) {
            throw ValidationException::withMessages(['currency_code' => "العملة {$code} غير معرّفة للمنشأة."]);
        }
        $rate = (float) $rate;

        return [$code, $rate > 0 ? round($rate, 8) : $this->rate($code, $date)];
    }

    /** Base-currency equivalent of a document amount. */
    public static function toBase(float $amount, $rate): float
    {
        return round($amount * (float) ($rate ?: 1), 2);
    }

    /** Validation rules shared by every form that takes a currency. */
    public static function rules(): array
    {
        return [
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|gt:0|max:100000',
        ];
    }
}

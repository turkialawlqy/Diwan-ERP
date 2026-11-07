<?php

namespace App\Services\Tax;

use App\Models\SupplierPayment;
use Illuminate\Support\Carbon;

/** Withholding tax of a period: every payment to a non-resident that was cut, with the amount to remit. */
class WithholdingReport
{
    /** @return array{rows: array<int, array>, totals: array{gross: float, withheld: float}} */
    public function build(Carbon $from, Carbon $to): array
    {
        $labels = collect(config('wht.categories'))->map(fn ($c) => $c[0]);
        $rows = SupplierPayment::with('supplier:id,name,country,tax_number')->where('status', '!=', 'cancelled')->where('wht_amount', '>', 0)
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString().' 23:59:59'])->orderBy('payment_date')->orderBy('id')->get()
            ->map(function (SupplierPayment $p) use ($labels) {
                $rate = (float) ($p->exchange_rate ?: 1);

                return [
                    'date' => $p->payment_date->toDateString(), 'number' => $p->payment_number, 'supplier' => $p->supplier?->name ?? '—', 'country' => $p->supplier?->country ?? '',
                    'category' => $labels[$p->wht_category] ?? 'أخرى', 'gross' => round((float) $p->amount * $rate, 2), 'rate' => (float) $p->wht_rate, 'withheld' => round((float) $p->wht_amount * $rate, 2),
                ];
            })->all();

        return ['rows' => $rows, 'totals' => ['gross' => round(array_sum(array_column($rows, 'gross')), 2), 'withheld' => round(array_sum(array_column($rows, 'withheld')), 2)]];
    }
}

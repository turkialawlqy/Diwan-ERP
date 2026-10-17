<?php

namespace App\Services\Accounting;

use App\Models\AssetDepreciation;
use App\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly depreciation of fixed assets (straight line or declining balance),
 * acquisition and disposal postings.
 */
class DepreciationService
{
    public function __construct(
        protected PostingService $posting,
        protected DocumentPoster $documents,
    ) {}

    /**
     * Acquisition: Dr asset account (+ input VAT) / Cr cash, bank or payables.
     * An opening asset brings its accumulated depreciation and is posted on the
     * date depreciation starts in this system (the go-live month).
     */
    public function acquire(FixedAsset $asset, string $paidFrom, ?int $bankAccountId = null, ?int $supplierId = null): void
    {
        $asset->loadMissing('category');
        $cost = (float) $asset->cost;
        $vat = $paidFrom === 'opening' ? 0.0 : round((float) $asset->acquisition_vat, 2);
        $opening = $paidFrom === 'opening' ? round((float) $asset->opening_accumulated, 2) : 0.0;

        $credit = match ($paidFrom) {
            'payables' => ['role' => 'payables', 'party_type' => 'supplier', 'party_id' => $supplierId],
            'opening' => ['role' => 'opening_balance_equity'],
            default => ['account_id' => $this->documents->cashAccount($bankAccountId, $paidFrom)],
        };

        $entry = $this->posting->post([
            'date' => $paidFrom === 'opening' ? $asset->depreciation_start_date : $asset->acquisition_date,
            'description' => "شراء أصل ثابت {$asset->code} - {$asset->name_ar}",
            'entry_type' => $paidFrom === 'opening' ? 'opening' : null,
            'source' => $asset,
            'lines' => array_values(array_filter([
                ['account_id' => $asset->category->asset_account_id, 'debit' => $cost, 'cost_center_id' => $asset->cost_center_id],
                $vat > 0 ? ['role' => 'vat_input', 'debit' => $vat, 'description' => "ضريبة مدخلات - أصل {$asset->code}"] : null,
                $opening > 0 ? ['account_id' => $asset->category->accumulated_account_id, 'credit' => $opening, 'description' => 'مجمع الإهلاك السابق'] : null,
                array_merge($credit, ['credit' => round($cost + $vat - $opening, 2)]),
            ])),
        ]);

        $asset->update(['acquisition_entry_id' => $entry->id]);
    }

    /**
     * Depreciate every active asset up to (and including) the given month,
     * catching up any month that was missed. Idempotent: a month already
     * depreciated for an asset is skipped.
     */
    public function run(Carbon $month): array
    {
        $month = $month->copy()->endOfMonth();
        $posted = 0;
        $total = 0.0;

        FixedAsset::with('category')
            ->where('status', 'active')
            ->where('depreciation_start_date', '<=', $month->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($assets) use ($month, &$posted, &$total) {
                foreach ($assets as $asset) {
                    $amount = $this->depreciateThrough($asset, $month);
                    if ($amount > 0) {
                        $posted++;
                        $total += $amount;
                    }
                }
            });

        return ['assets' => $posted, 'amount' => round($total, 2)];
    }

    /**
     * Post every missing month of one asset up to $until (end of a month). With
     * $partialDate the last month is pro-rated to that day (disposal).
     */
    public function depreciateThrough(FixedAsset $asset, Carbon $until, ?Carbon $partialDate = null): float
    {
        $asset->loadMissing('category');
        $cursor = Carbon::parse($asset->depreciation_start_date)->startOfMonth();
        $done = AssetDepreciation::where('fixed_asset_id', $asset->id)->pluck('period')->map(fn ($p) => (int) $p)->all();
        $total = 0.0;

        while ($cursor->lte($until) && $asset->status === 'active') {
            $monthEnd = $cursor->copy()->endOfMonth();
            $period = (int) $monthEnd->format('Ym');
            $isPartial = $partialDate && $period === (int) $partialDate->format('Ym');

            if (! in_array($period, $done, true)) {
                $amount = $this->monthlyAmount($asset);
                if ($isPartial) {
                    $amount = round($amount * $partialDate->day / $monthEnd->daysInMonth, 2);
                }
                if ($amount <= 0) {
                    break;
                }
                $this->postMonth($asset, $isPartial ? $partialDate : $monthEnd, $period, $amount);
                $total += $amount;
            }
            $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return round($total, 2);
    }

    protected function postMonth(FixedAsset $asset, Carbon $date, int $period, float $amount): void
    {
        DB::transaction(function () use ($asset, $date, $period, $amount) {
            $entry = $this->posting->post([
                'date' => $date,
                'description' => "إهلاك شهر {$date->format('Y-m')} - {$asset->code} {$asset->name_ar}",
                'source' => $asset,
                'lines' => [
                    ['account_id' => $asset->category->expense_account_id, 'debit' => $amount, 'cost_center_id' => $asset->cost_center_id],
                    ['account_id' => $asset->category->accumulated_account_id, 'credit' => $amount],
                ],
            ]);

            AssetDepreciation::create([
                'fixed_asset_id' => $asset->id,
                'period' => $period,
                'amount' => $amount,
                'journal_entry_id' => $entry->id,
            ]);

            $accumulated = round((float) $asset->accumulated_depreciation + $amount, 2);
            $asset->update([
                'accumulated_depreciation' => $accumulated,
                'last_depreciation_date' => $date->toDateString(),
                'status' => $accumulated >= (float) $asset->cost - (float) $asset->salvage_value - 0.005 ? 'fully_depreciated' : 'active',
            ]);
        });
    }

    public function monthlyAmount(FixedAsset $asset): float
    {
        $depreciable = (float) $asset->cost - (float) $asset->salvage_value;
        $remaining = round($depreciable - (float) $asset->accumulated_depreciation, 2);

        if ($remaining <= 0 || $asset->useful_life_months <= 0) {
            return 0.0;
        }

        if ($asset->depreciation_method === 'declining_balance') {
            $rate = (float) ($asset->category->declining_rate ?: (200 / max(1, $asset->useful_life_months / 12)));
            $bookValue = (float) $asset->cost - (float) $asset->accumulated_depreciation;
            $amount = round($bookValue * ($rate / 100) / 12, 2);
        } else {
            $amount = round($depreciable / $asset->useful_life_months, 2);
        }

        return min($amount, $remaining);
    }

    /**
     * Disposal / sale: remove cost and accumulated depreciation, book proceeds
     * and the gain or loss.
     */
    public function dispose(FixedAsset $asset, Carbon $date, float $proceeds, ?int $bankAccountId = null, string $method = 'cash', float $vat = 0.0): void
    {
        if ($asset->status === 'disposed') {
            throw new AccountingException('الأصل مستبعد مسبقاً.');
        }
        if ($date->lt(Carbon::parse($asset->acquisition_date))) {
            throw new AccountingException('تاريخ الاستبعاد قبل تاريخ شراء الأصل.');
        }

        $asset->loadMissing('category');
        $vat = round(max(0, $vat), 2);

        // Depreciation runs up to the disposal date before the book value is measured.
        if ($asset->status === 'active' && $date->gte(Carbon::parse($asset->depreciation_start_date)->startOfMonth())) {
            $this->depreciateThrough($asset, $date->copy()->endOfMonth(), $date);
            $asset->refresh();
        }

        $cost = (float) $asset->cost;
        $accumulated = (float) $asset->accumulated_depreciation;
        $bookValue = round($cost - $accumulated, 2);
        $gain = round($proceeds - $bookValue, 2);

        DB::transaction(function () use ($asset, $date, $proceeds, $bankAccountId, $method, $cost, $accumulated, $gain, $vat) {
            $lines = [
                ['account_id' => $asset->category->accumulated_account_id, 'debit' => $accumulated],
                ['account_id' => $asset->category->asset_account_id, 'credit' => $cost],
            ];
            if ($proceeds + $vat > 0) {
                $lines[] = ['account_id' => $this->documents->cashAccount($bankAccountId, $method), 'debit' => round($proceeds + $vat, 2)];
            }
            if ($vat > 0) {
                $lines[] = ['role' => 'vat_output', 'credit' => $vat, 'description' => "ضريبة مخرجات - بيع أصل {$asset->code}"];
            }
            if ($gain > 0) {
                $lines[] = ['role' => 'asset_disposal_gain', 'credit' => $gain];
            } elseif ($gain < 0) {
                $lines[] = ['role' => 'asset_disposal_loss', 'debit' => -$gain];
            }

            $entry = $this->posting->post([
                'date' => $date,
                'description' => "استبعاد / بيع الأصل {$asset->code} - {$asset->name_ar}",
                'source' => $asset,
                'lines' => $lines,
            ]);

            $asset->update([
                'status' => 'disposed',
                'disposal_date' => $date->toDateString(),
                'disposal_amount' => $proceeds,
                'disposal_vat' => $vat,
                'disposal_entry_id' => $entry->id,
            ]);
        });
    }
}

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
     * Acquisition: Dr asset account / Cr cash, bank or payables.
     */
    public function acquire(FixedAsset $asset, string $paidFrom, ?int $bankAccountId = null, ?int $supplierId = null): void
    {
        $asset->loadMissing('category');

        $credit = match ($paidFrom) {
            'payables' => ['role' => 'payables', 'party_type' => 'supplier', 'party_id' => $supplierId],
            'opening' => ['role' => 'opening_balance_equity'],
            default => ['account_id' => $this->documents->cashAccount($bankAccountId, $paidFrom)],
        };

        $entry = $this->posting->post([
            'date' => $asset->acquisition_date,
            'description' => "شراء أصل ثابت {$asset->code} - {$asset->name_ar}",
            'source' => $asset,
            'lines' => [
                ['account_id' => $asset->category->asset_account_id, 'debit' => (float) $asset->cost, 'cost_center_id' => $asset->cost_center_id],
                array_merge($credit, ['credit' => (float) $asset->cost]),
            ],
        ]);

        $asset->update(['acquisition_entry_id' => $entry->id]);
    }

    /**
     * Depreciate every active asset up to (and including) the given month.
     * Idempotent: a month already depreciated for an asset is skipped.
     */
    public function run(Carbon $month): array
    {
        $month = $month->copy()->endOfMonth();
        $period = (int) $month->format('Ym');
        $posted = 0;
        $total = 0.0;

        FixedAsset::with('category')
            ->where('status', 'active')
            ->where('depreciation_start_date', '<=', $month->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($assets) use ($month, $period, &$posted, &$total) {
                foreach ($assets as $asset) {
                    if (AssetDepreciation::where('fixed_asset_id', $asset->id)->where('period', $period)->exists()) {
                        continue;
                    }

                    $amount = $this->monthlyAmount($asset);
                    if ($amount <= 0) {
                        continue;
                    }

                    DB::transaction(function () use ($asset, $month, $period, $amount, &$posted, &$total) {
                        $entry = $this->posting->post([
                            'date' => $month,
                            'description' => "إهلاك شهر {$month->format('Y-m')} - {$asset->code} {$asset->name_ar}",
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
                            'last_depreciation_date' => $month->toDateString(),
                            'status' => $accumulated >= (float) $asset->cost - (float) $asset->salvage_value - 0.005 ? 'fully_depreciated' : 'active',
                        ]);

                        $posted++;
                        $total += $amount;
                    });
                }
            });

        return ['assets' => $posted, 'amount' => round($total, 2)];
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
    public function dispose(FixedAsset $asset, Carbon $date, float $proceeds, ?int $bankAccountId = null, string $method = 'cash'): void
    {
        if ($asset->status === 'disposed') {
            throw new AccountingException('الأصل مستبعد مسبقاً.');
        }

        $asset->loadMissing('category');
        $cost = (float) $asset->cost;
        $accumulated = (float) $asset->accumulated_depreciation;
        $bookValue = round($cost - $accumulated, 2);
        $gain = round($proceeds - $bookValue, 2);

        DB::transaction(function () use ($asset, $date, $proceeds, $bankAccountId, $method, $cost, $accumulated, $gain) {
            $lines = [
                ['account_id' => $asset->category->accumulated_account_id, 'debit' => $accumulated],
                ['account_id' => $asset->category->asset_account_id, 'credit' => $cost],
            ];
            if ($proceeds > 0) {
                $lines[] = ['account_id' => $this->documents->cashAccount($bankAccountId, $method), 'debit' => $proceeds];
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
                'disposal_entry_id' => $entry->id,
            ]);
        });
    }
}

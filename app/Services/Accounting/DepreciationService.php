<?php

namespace App\Services\Accounting;

use App\Models\AssetDepreciation;
use App\Models\AssetImpairment;
use App\Models\AssetUsage;
use App\Models\Equipment;
use App\Models\EquipmentReading;
use App\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly depreciation of fixed assets (straight line, declining balance or
 * units of production), impairment, acquisition and disposal postings.
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
                $amount = $this->monthlyAmount($asset, $period);
                // Units of production: a month without usage depreciates nothing (the usage itself is not prorated).
                if ($isPartial && $asset->depreciation_method !== 'units_of_production') {
                    $amount = round($amount * $partialDate->day / $monthEnd->daysInMonth, 2);
                }
                if ($amount <= 0) {
                    if ($asset->depreciation_method === 'units_of_production' && $this->remaining($asset) > 0) {
                        $cursor->addMonthNoOverflow()->startOfMonth();

                        continue;
                    }
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
                'status' => $accumulated + (float) $asset->accumulated_impairment >= (float) $asset->cost - (float) $asset->salvage_value - 0.005 ? 'fully_depreciated' : 'active',
            ]);
        });
    }

    /**
     * The months still to come, as the monthly run would post them (nothing
     * is posted). Units of production depends on future usage: none.
     * Declining balance stops at the end of the useful life, leaving the
     * book value it has not reached.
     *
     * @return array<int, array{period: int, amount: float, accumulated: float, book_value: float}>
     */
    public function schedule(FixedAsset $asset): array
    {
        if ($asset->status !== 'active' || $asset->depreciation_method === 'units_of_production') {
            return [];
        }
        $asset->loadMissing('category');
        $simulated = $asset->replicate();
        $simulated->setRelation('category', $asset->category);

        $done = AssetDepreciation::where('fixed_asset_id', $asset->id)->count();
        $last = AssetDepreciation::where('fixed_asset_id', $asset->id)->max('period');
        $cursor = $last ? $this->monthStart((int) $last)->addMonthNoOverflow()
            : Carbon::parse($asset->depreciation_start_date)->startOfMonth();
        $months = $asset->depreciation_method === 'declining_balance' ? max(0, $asset->useful_life_months - $done) : 1200;

        $rows = [];
        while (count($rows) < $months) {
            $period = (int) $cursor->format('Ym');
            $amount = $this->monthlyAmount($simulated, $period);
            if ($amount <= 0) {
                break;
            }
            $simulated->accumulated_depreciation = round((float) $simulated->accumulated_depreciation + $amount, 2);
            $written = round((float) $simulated->accumulated_depreciation + (float) $simulated->accumulated_impairment, 2);
            $rows[] = ['period' => $period, 'amount' => $amount, 'accumulated' => $written, 'book_value' => round((float) $asset->cost - $written, 2)];
            $cursor->addMonthNoOverflow();
        }

        return $rows;
    }

    /** First day of a Ym period (createFromFormat would take today's day and overflow short months). */
    protected function monthStart(int $period): Carbon
    {
        return Carbon::create(intdiv($period, 100), $period % 100, 1)->startOfDay();
    }

    /** Depreciable amount not yet written off (by depreciation or impairment). */
    public function remaining(FixedAsset $asset): float
    {
        return round((float) $asset->cost - (float) $asset->salvage_value - (float) $asset->accumulated_depreciation - (float) $asset->accumulated_impairment, 2);
    }

    /**
     * Depreciation of one month (Ym; defaults to the current month). After an
     * impairment the remaining amount is spread over the remaining life.
     */
    public function monthlyAmount(FixedAsset $asset, ?int $period = null): float
    {
        $period ??= (int) now()->format('Ym');
        $remaining = $this->remaining($asset);

        if ($remaining <= 0 || ($asset->useful_life_months <= 0 && $asset->depreciation_method !== 'units_of_production')) {
            return 0.0;
        }

        if ($asset->depreciation_method === 'units_of_production') {
            $units = $this->unitsFor($asset, $period);
            $unitsLeft = (float) $asset->total_units - $this->unitsBefore($asset, $period);
            if ($units <= 0 || (float) $asset->total_units <= 0) {
                return 0.0;
            }
            $amount = round($remaining * $units / max($units, $unitsLeft), 2);
        } elseif ($asset->depreciation_method === 'declining_balance') {
            $asset->loadMissing('category');
            $rate = (float) ($asset->category->declining_rate ?: (200 / max(1, $asset->useful_life_months / 12)));
            $bookValue = (float) $asset->cost - (float) $asset->accumulated_depreciation - (float) $asset->accumulated_impairment;
            $amount = round($bookValue * ($rate / 100) / 12, 2);
        } elseif ((float) $asset->accumulated_impairment > 0) {
            $month = $this->monthStart($period);
            $elapsed = (int) Carbon::parse($asset->acquisition_date)->startOfMonth()->diffInMonths($month);
            $amount = round($remaining / max(1, $asset->useful_life_months - $elapsed), 2);
        } else {
            $amount = round(((float) $asset->cost - (float) $asset->salvage_value) / $asset->useful_life_months, 2);
        }

        return min($amount, $remaining);
    }

    /**
     * Units of a month: entered on the asset, or else the hours worked by the
     * equipment linked to it.
     */
    public function unitsFor(FixedAsset $asset, int $period): float
    {
        $entered = AssetUsage::where('fixed_asset_id', $asset->id)->where('period', $period)->value('units');
        if ($entered !== null) {
            return (float) $entered;
        }
        $equipmentId = Equipment::where('fixed_asset_id', $asset->id)->value('id');
        if (! $equipmentId) {
            return 0.0;
        }
        $month = $this->monthStart($period);

        return (float) EquipmentReading::where('equipment_id', $equipmentId)
            ->whereDate('date', '>=', $month)->whereDate('date', '<=', $month->copy()->endOfMonth())->sum('worked_hours');
    }

    /** Units of the months already depreciated before $period. */
    protected function unitsBefore(FixedAsset $asset, int $period): float
    {
        return (float) AssetDepreciation::where('fixed_asset_id', $asset->id)->where('period', '<', $period)->pluck('period')
            ->sum(fn ($p) => $this->unitsFor($asset, (int) $p));
    }

    /**
     * Impairment (IAS 36): the asset's carrying amount is written down to its
     * recoverable amount. Depreciation is first brought up to the previous
     * month so the loss is measured on the current book value.
     *
     *   Dr Impairment loss / Cr Accumulated depreciation (of the category)
     */
    public function impair(FixedAsset $asset, Carbon $date, float $amount, ?string $reason = null): AssetImpairment
    {
        if (! in_array($asset->status, ['active', 'fully_depreciated'], true)) {
            throw new AccountingException('لا يمكن تسجيل هبوط قيمة لأصل مستبعد.');
        }
        $asset->loadMissing('category');
        if ($asset->status === 'active' && $date->copy()->startOfMonth()->gt(Carbon::parse($asset->depreciation_start_date)->startOfMonth())) {
            $this->depreciateThrough($asset, $date->copy()->startOfMonth()->subDay());
            $asset->refresh();
        }

        $amount = round($amount, 2);
        $max = $this->remaining($asset);
        if ($amount <= 0 || $amount > $max + 0.005) {
            throw new AccountingException('مبلغ هبوط القيمة يجب أن يكون أكبر من صفر ولا يتجاوز القيمة الدفترية القابلة للإهلاك ('.number_format($max, 2).').');
        }

        return DB::transaction(function () use ($asset, $date, $amount, $reason) {
            $entry = $this->posting->post([
                'date' => $date,
                'description' => "هبوط قيمة الأصل {$asset->code} - {$asset->name_ar}",
                'source' => $asset,
                'lines' => [
                    ['role' => 'impairment_loss', 'debit' => $amount, 'cost_center_id' => $asset->cost_center_id],
                    ['account_id' => $asset->category->accumulated_account_id, 'credit' => $amount, 'description' => 'هبوط قيمة'],
                ],
            ]);

            $impaired = round((float) $asset->accumulated_impairment + $amount, 2);
            $asset->update([
                'accumulated_impairment' => $impaired,
                'status' => (float) $asset->accumulated_depreciation + $impaired >= (float) $asset->cost - (float) $asset->salvage_value - 0.005 ? 'fully_depreciated' : $asset->status,
            ]);

            return AssetImpairment::create([
                'fixed_asset_id' => $asset->id, 'date' => $date->toDateString(), 'amount' => $amount,
                'reason' => $reason, 'journal_entry_id' => $entry->id, 'created_by' => auth()->id(),
            ]);
        });
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
        // Impairment losses sit in the same accumulated account and leave with the asset.
        $accumulated = round((float) $asset->accumulated_depreciation + (float) $asset->accumulated_impairment, 2);
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

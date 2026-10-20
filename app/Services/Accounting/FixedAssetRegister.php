<?php

namespace App\Services\Accounting;

use App\Models\AssetCategory;
use App\Models\AssetDepreciation;
use App\Models\AssetImpairment;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;

/**
 * Fixed asset register for a period (the property, plant and equipment
 * note): cost and accumulated depreciation at the start, additions,
 * depreciation, impairment and disposals in the period, and the closing
 * net book value, by category and asset, checked against the ledger.
 */
class FixedAssetRegister
{
    public const COLUMNS = ['cost_open', 'additions', 'disposals', 'cost_close', 'acc_open', 'acc_added', 'depreciation', 'impairment', 'acc_disposed', 'acc_close', 'book_value'];

    public function __construct(protected AccountingReports $reports) {}

    public function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $fromPeriod = (int) $from->format('Ym');
        $toPeriod = (int) $to->format('Ym');

        $assets = FixedAsset::with('category:id,name_ar')->orderBy('code')->get();
        // An asset enters the books on its acquisition entry (an opening asset on its go-live date).
        $recognised = JournalEntry::whereIn('id', $assets->pluck('acquisition_entry_id')->filter())->pluck('date', 'id');
        $depreciation = AssetDepreciation::whereIn('fixed_asset_id', $assets->pluck('id'))->get()->groupBy('fixed_asset_id');
        $impairments = AssetImpairment::whereIn('fixed_asset_id', $assets->pluck('id'))->get()->groupBy('fixed_asset_id');

        $rows = [];
        foreach ($assets as $asset) {
            $in = Carbon::parse($recognised[$asset->acquisition_entry_id] ?? $asset->acquisition_date);
            $out = $asset->disposal_date ? Carbon::parse($asset->disposal_date) : null;
            if ($in->gt($to) || ($out && $out->lt($from))) {
                continue;
            }

            $before = $in->lt($from);
            $disposedNow = $out && $out->between($from, $to);
            $deps = $depreciation->get($asset->id, collect());
            $imps = $impairments->get($asset->id, collect());
            $cost = (float) $asset->cost;
            $openingAcc = (float) $asset->opening_accumulated;

            $r = [
                'cost_open' => $before ? $cost : 0.0,
                'additions' => $before ? 0.0 : $cost,
                'disposals' => $disposedNow ? $cost : 0.0,
                'acc_open' => $before ? $openingAcc + (float) $deps->where('period', '<', $fromPeriod)->sum('amount') + (float) $imps->filter(fn ($i) => $i->date->lt($from))->sum('amount') : 0.0,
                'acc_added' => $before ? 0.0 : $openingAcc,
                'depreciation' => (float) $deps->whereBetween('period', [$fromPeriod, $toPeriod])->sum('amount'),
                'impairment' => (float) $imps->filter(fn ($i) => $i->date->between($from, $to))->sum('amount'),
            ];
            $r['acc_disposed'] = $disposedNow ? $r['acc_open'] + $r['acc_added'] + $r['depreciation'] + $r['impairment'] : 0.0;
            $r['cost_close'] = $r['cost_open'] + $r['additions'] - $r['disposals'];
            $r['acc_close'] = $r['acc_open'] + $r['acc_added'] + $r['depreciation'] + $r['impairment'] - $r['acc_disposed'];
            $r['book_value'] = $r['cost_close'] - $r['acc_close'];

            $rows[] = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r) + [
                'id' => $asset->id, 'code' => $asset->code, 'name' => $asset->name_ar, 'status' => $asset->status,
                'category_id' => $asset->asset_category_id, 'category' => $asset->category?->name_ar,
            ];
        }

        $sum = fn ($items) => collect(self::COLUMNS)->mapWithKeys(fn ($c) => [$c => round(collect($items)->sum($c), 2)])->all();
        $categories = collect($rows)->groupBy('category_id')->map(fn ($items) => [
            'name' => $items->first()['category'], 'assets' => $items->values(), 'totals' => $sum($items),
        ])->sortBy('name')->values();
        $totals = $sum($rows);

        return [
            'categories' => $categories,
            'totals' => $totals,
            'ledger' => $this->ledger($to, $totals),
        ];
    }

    /** Balances of the categories' asset and accumulated depreciation accounts at the end date. */
    protected function ledger(Carbon $to, array $totals): array
    {
        $categories = AssetCategory::query()->get(['asset_account_id', 'accumulated_account_id']);
        $balance = function ($ids, bool $credit) use ($to) {
            $ids = collect($ids)->filter()->unique()->values()->all();

            return $ids ? round(collect($this->reports->totalsBetween(null, $to, $ids))->sum(fn ($t) => $credit ? $t['credit'] - $t['debit'] : $t['debit'] - $t['credit']), 2) : 0.0;
        };
        $cost = $balance($categories->pluck('asset_account_id'), false);
        $accumulated = $balance($categories->pluck('accumulated_account_id'), true);

        return [
            'cost' => $cost,
            'accumulated' => $accumulated,
            'cost_difference' => round($cost - $totals['cost_close'], 2),
            'accumulated_difference' => round($accumulated - $totals['acc_close'], 2),
        ];
    }
}

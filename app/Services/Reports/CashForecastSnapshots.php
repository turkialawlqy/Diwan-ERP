<?php

namespace App\Services\Reports;

use App\Models\CashForecastSnapshot;
use App\Services\Accounting\AccountingControls;
use App\Services\ActiveBranch;
use Illuminate\Support\Carbon;

/**
 * Keeps the cash forecast as it was on a day and compares each of its weeks with the cash that really moved
 * (the boxes' and banks' receipts minus payments), so the forecast's accuracy can be judged.
 */
class CashForecastSnapshots
{
    public function __construct(protected Insights $insights, protected AccountingControls $controls) {}

    /** Store today's forecast (one snapshot per day and branch scope; a second one the same day replaces the first). */
    public function take(?Carbon $today = null, ?int $userId = null): CashForecastSnapshot
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $forecast = $this->insights->cashForecast(13, $today);
        $scope = app(ActiveBranch::class)->scopeKey();

        $snapshot = CashForecastSnapshot::where('scope', $scope)->whereDate('taken_on', $today->toDateString())->first() ?? new CashForecastSnapshot(['scope' => $scope, 'taken_on' => $today->toDateString()]);
        $snapshot->fill([
            'opening' => $forecast['opening'],
            'weeks' => array_map(fn ($w) => ['start' => $w['start'], 'end' => $w['end'], 'inflow' => $w['inflow'], 'outflow' => $w['outflow'], 'net' => $w['net'], 'balance' => $w['balance']], $forecast['weeks']),
            'scenario' => $forecast['scenario'],
            'taken_by' => $userId,
        ])->save();

        return $snapshot;
    }

    /**
     * Forecast against actual for every elapsed week of the latest snapshots (at most $limit snapshots).
     *
     * @return array{rows: array<int, array>, accuracy: ?float}
     */
    public function compare(int $limit = 6, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $scope = app(ActiveBranch::class)->scopeKey();
        $rows = [];
        $scores = [];

        CashForecastSnapshot::where('scope', $scope)->whereDate('taken_on', '<', $today->toDateString())->orderByDesc('taken_on')->limit($limit)->get()
            ->each(function (CashForecastSnapshot $snap) use (&$rows, &$scores, $today) {
                foreach ($snap->weeks as $week) {
                    $end = Carbon::parse($week['end'])->startOfDay();
                    if ($end->gte($today)) {
                        continue;   // the week is not over yet
                    }
                    $movement = $this->controls->cashMovement(Carbon::parse($week['start']), $end);
                    $actual = round(collect($movement['accounts'])->sum(fn ($a) => $a['in'] - $a['out']), 2);
                    $deviation = round($actual - (float) $week['net'], 2);
                    $base = max(abs($actual), abs((float) $week['net']), 1.0);
                    $accuracy = round(max(0.0, 100 - abs($deviation) / $base * 100), 1);
                    $scores[] = $accuracy;
                    $rows[] = ['snapshot' => $snap->taken_on->toDateString(), 'week' => $week['start'].' → '.$week['end'], 'forecast' => (float) $week['net'], 'actual' => $actual, 'deviation' => $deviation, 'accuracy' => $accuracy];
                }
            });

        return ['rows' => $rows, 'accuracy' => $scores ? round(array_sum($scores) / count($scores), 1) : null];
    }
}

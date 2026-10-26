<?php

namespace App\Services\Accounting;

use App\Models\CompanySetting;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Fiscal years and monthly periods. Years are created on demand (calendar
 * years by default, or starting at the company's configured month).
 */
class FiscalCalendar
{
    /**
     * The period containing the date; creates the fiscal year when missing.
     */
    public function periodFor(CarbonInterface|string $date, int $companyId): FiscalPeriod
    {
        $date = Carbon::parse($date)->startOfDay();

        $period = FiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            // Inclusive whole-day bounds (date columns may carry a 00:00:00 time on some drivers).
            ->where('start_date', '<=', $date->toDateString().' 23:59:59')
            ->where('end_date', '>=', $date->toDateString())
            ->first();

        if ($period) {
            return $period;
        }

        $this->createYear($this->yearStartFor($date, $companyId), $companyId);

        return FiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            // Inclusive whole-day bounds (date columns may carry a 00:00:00 time on some drivers).
            ->where('start_date', '<=', $date->toDateString().' 23:59:59')
            ->where('end_date', '>=', $date->toDateString())
            ->firstOrFail();
    }

    /**
     * Throws when the date falls in a closed or locked period.
     */
    public function assertOpen(CarbonInterface|string $date, int $companyId): FiscalPeriod
    {
        $period = $this->periodFor($date, $companyId);

        if (! $period->isOpen()) {
            throw new AccountingException("الفترة المحاسبية ({$period->name}) مغلقة. لا يمكن الترحيل بتاريخ ".Carbon::parse($date)->toDateString().'.');
        }

        return $period;
    }

    public function createYear(CarbonInterface $start, int $companyId): FiscalYear
    {
        $start = Carbon::parse($start)->startOfMonth();
        $end = $start->copy()->addYear()->subDay();

        $existing = FiscalYear::withoutGlobalScopes()->where('company_id', $companyId)->whereDate('start_date', $start->toDateString())->first();
        if ($existing) {
            return $existing;
        }

        $year = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'name' => $start->month === 1 ? (string) $start->year : $start->year.'/'.$end->year,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'status' => 'open',
        ]);

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonth()) {
            FiscalPeriod::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'fiscal_year_id' => $year->id,
                'name' => $cursor->format('Y-m'),
                'start_date' => $cursor->copy()->startOfMonth()->toDateString(),
                'end_date' => $cursor->copy()->endOfMonth()->toDateString(),
                'status' => 'open',
            ]);
        }

        return $year;
    }

    /**
     * First day of the fiscal year containing the date, honouring the
     * company's "fiscal_year_start_month" setting (default January).
     */
    public function yearStartFor(CarbonInterface $date, int $companyId): Carbon
    {
        $startMonth = (int) (CompanySetting::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('key', 'fiscal_year_start_month')
            ->value('value') ?: 1);

        $start = Carbon::create($date->year, $startMonth, 1);

        return $start->gt($date) ? $start->subYear() : $start;
    }
}

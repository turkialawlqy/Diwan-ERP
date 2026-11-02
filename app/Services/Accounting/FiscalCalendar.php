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
     * Date for undoing a document: its own date while that period is open, otherwise
     * today (the same rule PostingService::reverse applies to the journal entry).
     */
    public function reversalDate(CarbonInterface|string|null $date, int $companyId): string
    {
        return $date && $this->periodFor($date, $companyId)->isOpen()
            ? Carbon::parse($date)->toDateString()
            : now()->toDateString();
    }

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

    public function createYear(CarbonInterface $start, int $companyId, ?string $type = null, ?CarbonInterface $end = null, ?string $name = null): FiscalYear
    {
        $type = array_key_exists((string) $type, FiscalYear::PERIOD_TYPES) ? $type : $this->defaultPeriodType($companyId);
        $start = Carbon::parse($start)->startOfMonth();
        $end = $end ? Carbon::parse($end)->endOfMonth()->startOfDay() : $start->copy()->addYear()->subDay();

        $existing = FiscalYear::withoutGlobalScopes()->where('company_id', $companyId)->whereDate('start_date', $start->toDateString())->first();
        if ($existing) {
            return $existing;
        }

        $year = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'name' => $name ?: $this->defaultName($start, $end),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'period_type' => $type,
            'status' => 'open',
        ]);

        foreach ($this->periodRanges($start, $type, $year->name, $end) as [$periodName, $from, $to]) {
            FiscalPeriod::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'fiscal_year_id' => $year->id,
                'name' => $periodName,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
                'status' => 'open',
            ]);
        }

        return $year;
    }

    /** "2026" for a calendar year, "2026/2027" for one that starts mid-year. */
    public function defaultName(CarbonInterface $start, CarbonInterface $end): string
    {
        return $start->month === 1 && $end->year === $start->year ? (string) $start->year : $start->year.'/'.$end->year;
    }

    /**
     * The periods of a year from $start to $end (default: 12 months): [name, from, to, Arabic label] each.
     *
     * @return array<int, array{0: string, 1: Carbon, 2: Carbon, 3: string}>
     */
    public function periodRanges(CarbonInterface $start, string $type, string $yearName, ?CarbonInterface $end = null): array
    {
        $start = Carbon::parse($start)->startOfMonth();
        $end = $end ? Carbon::parse($end)->endOfMonth()->startOfDay() : $start->copy()->addYear()->subDay();
        $months = (int) $start->diffInMonths($end->copy()->addDay()->startOfMonth());
        $ranges = [];
        $clip = fn (Carbon $date) => $date->gt($end) ? $end->copy() : $date;
        $monthNames = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        $ordinals = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس'];

        if ($type === 'quarterly' || $type === 'semiannual') {
            $step = $type === 'quarterly' ? 3 : 6;
            $letter = $type === 'quarterly' ? 'Q' : 'H';
            for ($i = 0; $i * $step < $months; $i++) {
                $from = $start->copy()->addMonths($i * $step);
                $to = $clip($from->copy()->addMonths($step)->subDay());
                $ranges[] = ["{$yearName}-{$letter}".($i + 1), $from, $to, ($type === 'quarterly' ? 'الربع ' : 'النصف ').($ordinals[$i] ?? ($i + 1))];
            }

            return $ranges;
        }

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $label = $monthNames[$month->month - 1];
            if ($type === 'semimonthly') {
                $ranges[] = [$month->format('Y-m').'-A', $month->copy(), $month->copy()->day(15), "{$label} (1-15)"];
                $last = $clip($month->copy()->endOfMonth()->startOfDay());
                $ranges[] = [$month->format('Y-m').'-B', $month->copy()->day(16), $last, "{$label} (16-{$last->day})"];
            } else {
                $ranges[] = [$month->format('Y-m'), $month->copy(), $clip($month->copy()->endOfMonth()->startOfDay()), $label];
            }
        }

        return $ranges;
    }

    /** Type used when a year is created on demand by a posting (company setting, default monthly). */
    public function defaultPeriodType(int $companyId): string
    {
        $type = CompanySetting::withoutGlobalScopes()->where('company_id', $companyId)->where('key', 'fiscal_period_type')->value('value');

        return array_key_exists((string) $type, FiscalYear::PERIOD_TYPES) ? $type : 'monthly';
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

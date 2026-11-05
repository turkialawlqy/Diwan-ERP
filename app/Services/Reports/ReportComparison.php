<?php

namespace App\Services\Reports;

use App\Services\Accounting\AccountingReports;
use Illuminate\Support\Carbon;

/** A statement next to an earlier one: the previous period of the same length, or the same period a year before. */
class ReportComparison
{
    public const MODES = ['previous', 'year'];

    public function __construct(protected AccountingReports $reports) {}

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    public function period(Carbon $from, Carbon $to, string $mode): array
    {
        if ($mode === 'year') {
            return [$from->copy()->subYear(), $to->copy()->subYear(), 'نفس الفترة من السنة السابقة'];
        }
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;

        return [$from->copy()->subDays($days), $from->copy()->subDay(), 'الفترة السابقة'];
    }

    /**
     * Income statement lines of both periods, one row per account: [section, code, name, current, previous, change, pct].
     * Summary rows (totals, gross profit, net profit) have `bold` set.
     */
    public function incomeStatement(Carbon $from, Carbon $to, string $mode, ?int $costCenterId = null): array
    {
        [$pFrom, $pTo, $label] = $this->period($from, $to, $mode);
        $current = $this->reports->incomeStatement($from, $to, $costCenterId);
        $previous = $this->reports->incomeStatement($pFrom, $pTo, $costCenterId);

        $rows = [];
        foreach (['revenue' => 'الإيرادات', 'expense' => 'المصروفات والتكاليف'] as $key => $section) {
            $rows[] = $this->row($section, '', $section, null, null, true, true);
            $accounts = collect($current[$key]['rows'])->keyBy('id');
            foreach (collect($previous[$key]['rows'])->keyBy('id') as $id => $row) {
                $accounts->has($id) || $accounts->put($id, ['id' => $id, 'code' => $row['code'], 'name' => $row['name'], 'amount' => 0.0]);
            }
            foreach ($accounts->sortBy('code') as $id => $row) {
                $before = (float) (collect($previous[$key]['rows'])->firstWhere('id', $id)['amount'] ?? 0);
                $rows[] = $this->row($section, $row['code'], $row['name'], (float) $row['amount'], $before);
            }
            $total = $key === 'revenue' ? 'total_revenue' : 'total_expense';
            $rows[] = $this->row($section, '', 'إجمالي '.$section, $current[$total], $previous[$total], true);
        }
        foreach (['cost_of_sales' => 'تكلفة المبيعات', 'gross_profit' => 'مجمل الربح', 'net_profit' => 'صافي الربح'] as $key => $name) {
            $rows[] = $this->row('', '', $name, $current[$key], $previous[$key], true);
        }

        return ['label' => $label, 'from' => $pFrom->toDateString(), 'to' => $pTo->toDateString(), 'rows' => $rows];
    }

    /** Balance sheet lines as of two dates (the second a year earlier or at the end of the previous period). */
    public function balanceSheet(Carbon $asOf, string $mode): array
    {
        $before = $mode === 'year' ? $asOf->copy()->subYear() : $asOf->copy()->subMonth()->endOfMonth();
        $current = $this->reports->balanceSheet($asOf);
        $previous = $this->reports->balanceSheet($before);

        $rows = [];
        foreach (['assets' => ['الأصول', 'total_assets'], 'liabilities' => ['الالتزامات', 'total_liabilities'], 'equity' => ['حقوق الملكية', 'total_equity']] as $key => [$section, $total]) {
            $rows[] = $this->row($section, '', $section, null, null, true, true);
            $accounts = collect($current[$key]['rows'])->keyBy('id');
            foreach (collect($previous[$key]['rows'])->keyBy('id') as $id => $row) {
                $accounts->has($id) || $accounts->put($id, ['id' => $id, 'code' => $row['code'], 'name' => $row['name'], 'amount' => 0.0]);
            }
            foreach ($accounts->sortBy('code') as $id => $row) {
                $rows[] = $this->row($section, $row['code'], $row['name'], (float) $row['amount'], (float) (collect($previous[$key]['rows'])->firstWhere('id', $id)['amount'] ?? 0));
            }
            $rows[] = $this->row($section, '', 'إجمالي '.$section, $current[$total], $previous[$total], true);
        }

        return ['label' => 'كما في '.$before->toDateString(), 'to' => $before->toDateString(), 'rows' => $rows];
    }

    protected function row(string $section, string $code, string $name, ?float $current, ?float $previous, bool $bold = false, bool $heading = false): array
    {
        $change = $current !== null && $previous !== null ? round($current - $previous, 2) : null;

        return [
            'section' => $section, 'code' => $code, 'name' => $name, 'current' => $current, 'previous' => $previous, 'change' => $change,
            'pct' => $change !== null && abs((float) $previous) > 0.004 ? round($change / abs((float) $previous) * 100, 1) : null,
            'bold' => $bold, 'heading' => $heading,
        ];
    }
}

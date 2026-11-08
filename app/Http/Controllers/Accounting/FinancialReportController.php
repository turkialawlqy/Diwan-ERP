<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\TaxSettlement;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\TaxSettlementService;
use App\Services\HR\EmployeeFinanceService;
use App\Services\Reports\ReportComparison;
use App\Services\Tax\WithholdingReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Financial statements and statutory reports built from the ledger.
 */
class FinancialReportController extends Controller
{
    public function __construct(protected AccountingReports $reports) {}

    public function incomeStatement(Request $request): Response
    {
        [$from, $to, $filters] = $this->range($request);
        $v = $request->validate(['cost_center_id' => 'nullable|tenant_exists:cost_centers,id', 'compare' => 'nullable|in:previous,year,budget']);
        $costCenterId = $v['cost_center_id'] ?? null;
        $compare = $v['compare'] ?? null;

        return Inertia::render('Accounting/Reports/IncomeStatement', [
            'report' => $this->reports->incomeStatement($from, $to, $costCenterId),
            'comparison' => $compare ? app(ReportComparison::class)->incomeStatement($from, $to, $compare, $costCenterId) : null,
            'costCenters' => CostCenter::orderBy('code')->get(['id', 'code', 'name_ar']),
            'filters' => array_merge($filters, ['cost_center_id' => $costCenterId, 'compare' => $compare]),
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $v = $request->validate(['as_of' => 'nullable|date', 'compare' => 'nullable|in:previous,year']);
        $asOf = Carbon::parse($v['as_of'] ?? now()->toDateString());
        $compare = $v['compare'] ?? null;

        return Inertia::render('Accounting/Reports/BalanceSheet', [
            'report' => $this->reports->balanceSheet($asOf),
            'comparison' => $compare ? app(ReportComparison::class)->balanceSheet($asOf, $compare) : null,
            // Part of the end-of-service provision falling due within a year (current liability).
            'eosCurrent' => Employee::exists() ? app(EmployeeFinanceService::class)->eosCurrentPortion($asOf) : 0,
            'filters' => ['as_of' => $asOf->toDateString(), 'compare' => $compare],
        ]);
    }

    public function cashFlow(Request $request): Response
    {
        [$from, $to, $filters] = $this->range($request);
        $method = $request->validate(['method' => 'nullable|in:direct,indirect'])['method'] ?? 'direct';

        return Inertia::render('Accounting/Reports/CashFlow', [
            'report' => $method === 'indirect' ? $this->reports->cashFlowIndirect($from, $to) : $this->reports->cashFlow($from, $to),
            'filters' => $filters + ['method' => $method],
        ]);
    }

    public function aging(Request $request): Response
    {
        $validated = $request->validate([
            'kind' => 'nullable|in:receivables,payables',
            'as_of' => 'nullable|date',
            'buckets' => ['nullable', 'regex:/^\d{1,4}(,\d{1,4}){0,5}$/'],
        ]);
        $kind = $validated['kind'] ?? 'receivables';
        $asOf = Carbon::parse($validated['as_of'] ?? now()->toDateString());
        $report = $this->reports->aging($kind, $asOf, isset($validated['buckets']) ? explode(',', $validated['buckets']) : [30, 60, 90]);

        return Inertia::render('Accounting/Reports/Aging', [
            'report' => $report,
            'filters' => ['kind' => $kind, 'as_of' => $asOf->toDateString(), 'buckets' => implode(',', $report['buckets'])],
        ]);
    }

    public function vatReturn(Request $request): Response
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Default: current VAT quarter.
        $quarterStart = now()->firstOfQuarter();
        $from = Carbon::parse($validated['start_date'] ?? $quarterStart->toDateString());
        $to = Carbon::parse($validated['end_date'] ?? $quarterStart->copy()->lastOfQuarter()->toDateString());

        return Inertia::render('Accounting/Reports/VatReturn', [
            'report' => $this->reports->vatReturn($from, $to),
            // A user limited to some branches sees those branches' rows only.
            'byBranch' => array_values(array_filter($this->reports->taxByBranch($from, $to), fn ($row) => auth()->user()?->canUseBranch($row['branch_id']) ?? true)),
            'filters' => ['start_date' => $from->toDateString(), 'end_date' => $to->toDateString()],
            'settlement' => app(TaxSettlementService::class)->preview($from, $to),
        ]);
    }

    /** Withholding tax of a period (quarter by default): what was cut from non-resident suppliers and is owed to the authority. */
    public function withholding(Request $request): Response
    {
        $v = $request->validate(['start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date']);
        $quarter = now()->firstOfQuarter();
        $from = Carbon::parse($v['start_date'] ?? $quarter->toDateString());
        $to = Carbon::parse($v['end_date'] ?? $quarter->copy()->lastOfQuarter()->toDateString());

        return Inertia::render('Accounting/Reports/Withholding', [
            'report' => app(WithholdingReport::class)->build($from, $to),
            'filters' => ['start_date' => $from->toDateString(), 'end_date' => $to->toDateString()],
        ]);
    }

    public function settleVat(Request $request, TaxSettlementService $settlements)
    {
        $data = $request->validate(['start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date']);
        $settlements->settleVat(Carbon::parse($data['start_date']), Carbon::parse($data['end_date']));

        return back()->with('success', 'تمت تسوية الإقرار وترحيل قيد التسوية');
    }

    public function cancelVatSettlement(TaxSettlement $settlement, TaxSettlementService $settlements)
    {
        $settlements->cancel($settlement);

        return back()->with('success', 'أُلغيت التسوية وعُكس قيدها');
    }

    protected function range(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $from = Carbon::parse($validated['start_date'] ?? now()->startOfYear()->toDateString());
        $to = Carbon::parse($validated['end_date'] ?? now()->toDateString());

        return [$from, $to, ['start_date' => $from->toDateString(), 'end_date' => $to->toDateString()]];
    }
}

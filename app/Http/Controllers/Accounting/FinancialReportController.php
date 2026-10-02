<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Services\Accounting\AccountingReports;
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
        $costCenterId = $request->validate(['cost_center_id' => 'nullable|tenant_exists:cost_centers,id'])['cost_center_id'] ?? null;

        return Inertia::render('Accounting/Reports/IncomeStatement', [
            'report' => $this->reports->incomeStatement($from, $to, $costCenterId),
            'costCenters' => CostCenter::orderBy('code')->get(['id', 'code', 'name_ar']),
            'filters' => array_merge($filters, ['cost_center_id' => $costCenterId]),
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $asOf = Carbon::parse($request->validate(['as_of' => 'nullable|date'])['as_of'] ?? now()->toDateString());

        return Inertia::render('Accounting/Reports/BalanceSheet', [
            'report' => $this->reports->balanceSheet($asOf),
            'filters' => ['as_of' => $asOf->toDateString()],
        ]);
    }

    public function cashFlow(Request $request): Response
    {
        [$from, $to, $filters] = $this->range($request);

        return Inertia::render('Accounting/Reports/CashFlow', [
            'report' => $this->reports->cashFlow($from, $to),
            'filters' => $filters,
        ]);
    }

    public function aging(Request $request): Response
    {
        $validated = $request->validate([
            'kind' => 'nullable|in:receivables,payables',
            'as_of' => 'nullable|date',
        ]);
        $kind = $validated['kind'] ?? 'receivables';
        $asOf = Carbon::parse($validated['as_of'] ?? now()->toDateString());

        return Inertia::render('Accounting/Reports/Aging', [
            'report' => $this->reports->aging($kind, $asOf),
            'filters' => ['kind' => $kind, 'as_of' => $asOf->toDateString()],
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
            'filters' => ['start_date' => $from->toDateString(), 'end_date' => $to->toDateString()],
        ]);
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

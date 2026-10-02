<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Services\Accounting\AccountingReports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TrialBalanceController extends Controller
{
    public function index(Request $request, AccountingReports $reports): Response
    {
        $filters = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'level' => 'nullable|integer|min:1|max:10',
            'show_zero' => 'nullable|boolean',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
        ]);

        $from = Carbon::parse($filters['start_date'] ?? now()->startOfYear()->toDateString());
        $to = Carbon::parse($filters['end_date'] ?? now()->toDateString());

        $report = $reports->trialBalance(
            $from,
            $to,
            ! ($filters['show_zero'] ?? false),
            $filters['level'] ?? null,
            $filters['cost_center_id'] ?? null,
        );

        return Inertia::render('Accounting/TrialBalance/Index', [
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'costCenters' => CostCenter::orderBy('code')->get(['id', 'code', 'name_ar']),
            'filters' => array_merge($filters, ['start_date' => $from->toDateString(), 'end_date' => $to->toDateString()]),
        ]);
    }
}

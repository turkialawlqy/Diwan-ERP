<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\SourceDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** «كشف حساب مجمع»: several accounts (or a parent account with everything under it) in one statement. */
class ConsolidatedStatementController extends Controller
{
    public function index(Request $request, AccountingReports $reports): Response
    {
        $filters = $request->validate([
            'account_ids' => 'nullable|array|max:100',
            'account_ids.*' => 'integer|tenant_exists:accounts,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
        ]);

        $from = Carbon::parse($filters['start_date'] ?? now()->startOfYear()->toDateString());
        $to = Carbon::parse($filters['end_date'] ?? now()->toDateString());
        $ids = array_values(array_unique(array_map('intval', $filters['account_ids'] ?? [])));

        $statement = null;
        if ($ids) {
            $statement = $reports->consolidated($ids, $from, $to, $this->perPage($request, 100, 500), isset($filters['cost_center_id']) ? (int) $filters['cost_center_id'] : null);
            $sources = app(SourceDocument::class);
            $statement['lines']->getCollection()->transform(function ($line) use ($sources) {
                $line->source = $sources->describe($line->source_type, $line->source_id);

                return $line;
            });
        }

        return Inertia::render('Accounting/Statements/Consolidated', [
            'accounts' => Account::orderBy('code')->get(['id', 'code', 'name_ar', 'is_parent']),
            'costCenters' => CostCenter::orderBy('code')->get(['id', 'code', 'name_ar']),
            'statement' => $statement,
            'filters' => [
                'account_ids' => $ids,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
                'cost_center_id' => $filters['cost_center_id'] ?? null,
            ],
        ]);
    }
}

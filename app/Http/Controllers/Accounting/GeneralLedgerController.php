<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Services\Accounting\AccountingReports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class GeneralLedgerController extends Controller
{
    public function index(Request $request, AccountingReports $reports): Response
    {
        $filters = $request->validate([
            'account_id' => 'nullable|tenant_exists:accounts,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'party_type' => 'nullable|in:customer,supplier,employee',
            'party_id' => 'nullable|integer',
        ]);

        $accounts = Account::where('is_parent', false)->orderBy('code')->get(['id', 'code', 'name_ar', 'nature']);
        $account = isset($filters['account_id']) ? Account::find($filters['account_id']) : $accounts->first();

        $from = Carbon::parse($filters['start_date'] ?? now()->startOfYear()->toDateString());
        $to = Carbon::parse($filters['end_date'] ?? now()->toDateString());

        $ledger = $account ? $reports->ledger(
            $account,
            $from,
            $to,
            $this->perPage($request, 50, 200),
            $filters['party_type'] ?? null,
            isset($filters['party_id']) ? (int) $filters['party_id'] : null,
            $filters['cost_center_id'] ?? null,
        ) : null;

        return Inertia::render('Accounting/Ledger/Index', [
            'accounts' => $accounts,
            'selectedAccount' => $account?->only(['id', 'code', 'name_ar', 'nature', 'type']),
            'ledger' => $ledger,
            'costCenters' => CostCenter::orderBy('code')->get(['id', 'code', 'name_ar']),
            'filters' => array_merge($filters, [
                'account_id' => $account?->id,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
            ]),
        ]);
    }
}

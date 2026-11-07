<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\BudgetService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Accounting/Budgets/Index', [
            'budgets' => Budget::with('fiscalYear:id,name')->orderByDesc('id')->get(),
            'years' => FiscalYear::orderByDesc('start_date')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'fiscal_year_id' => 'required|tenant_exists:fiscal_years,id',
        ]);

        $budget = Budget::create(array_merge($validated, ['status' => 'draft']));

        return redirect()->route('accounting.budgets.show', $budget->id);
    }

    /**
     * Budget grid (accounts x months) with actuals and variance.
     */
    public function show(Request $request, Budget $budget, AccountingReports $reports): Response
    {
        $scope = $this->scope($request);
        $budget->load('fiscalYear');
        $year = $budget->fiscalYear;
        $months = [];
        for ($m = Carbon::parse($year->start_date); $m->lte($year->end_date); $m->addMonth()) {
            $months[] = (int) $m->format('Ym');
        }

        $accounts = Account::whereIn('type', ['revenue', 'expense'])->where('is_parent', false)->orderBy('code')->get(['id', 'code', 'name_ar', 'type']);

        $lines = $this->scopedLines($budget, $scope)->get()
            ->groupBy('account_id')
            ->map(fn ($rows) => $rows->pluck('amount', 'period')->map(fn ($v) => (float) $v));

        if ($scope) {
            // A dimension's actual comes from the ledger lines tagged with it (cost centre / project) or its entries (branch).
            [$dimension, $id] = $scope;
            $actualByMonth = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.company_id', $budget->company_id)->whereIn('l.account_id', $accounts->pluck('id'))
                ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
                ->when($dimension === 'branch', fn ($q) => $q->where('e.branch_id', $id), fn ($q) => $q->where('l.'.BudgetService::DIMENSIONS[$dimension], $id))
                ->whereBetween('e.date', [Carbon::parse($year->start_date)->toDateString(), Carbon::parse($year->end_date)->toDateString().' 23:59:59'])
                ->groupBy('l.account_id', 'e.date')->selectRaw('l.account_id, e.date, SUM(l.debit) as debit, SUM(l.credit) as credit')->get()
                ->map(fn ($r) => (object) ['account_id' => $r->account_id, 'period' => (int) Carbon::parse($r->date)->format('Ym'), 'debit' => (float) $r->debit, 'credit' => (float) $r->credit])
                ->groupBy('account_id')
                ->map(fn ($rows) => $rows->groupBy('period')->map(fn ($g) => (object) ['period' => $g->first()->period, 'debit' => $g->sum('debit'), 'credit' => $g->sum('credit')])->values());
        } else {
            $actualByMonth = DB::table('account_balances')
                ->where('company_id', $budget->company_id)
                ->whereBetween('period', [min($months), max($months)])
                ->whereIn('account_id', $accounts->pluck('id'))
                ->get(['account_id', 'period', 'debit', 'credit'])
                ->groupBy('account_id');
        }

        $rows = $accounts->map(function ($account) use ($lines, $actualByMonth, $months) {
            $planned = $lines->get($account->id, collect());
            $actual = collect($actualByMonth->get($account->id, []))->mapWithKeys(fn ($b) => [
                $b->period => round($account->type === 'expense' ? $b->debit - $b->credit : $b->credit - $b->debit, 2),
            ]);

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name_ar,
                'type' => $account->type,
                'planned' => collect($months)->mapWithKeys(fn ($p) => [$p => (float) ($planned[$p] ?? 0)]),
                'actual' => collect($months)->mapWithKeys(fn ($p) => [$p => (float) ($actual[$p] ?? 0)]),
            ];
        });

        return Inertia::render('Accounting/Budgets/Show', [
            'budget' => $budget,
            'months' => $months,
            'rows' => $rows,
            'scope' => $scope ? ['dimension' => $scope[0], 'id' => $scope[1]] : null,
            'dimensionOptions' => [
                'cost_center' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->code.' - '.$c->name_ar]),
                'project' => Project::orderBy('code')->get(['id', 'code', 'name'])->map(fn ($p) => ['id' => $p->id, 'label' => $p->code.' - '.$p->name]),
                'branch' => Branch::orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'label' => $b->name]),
            ],
        ]);
    }

    /** @return array{0: string, 1: int}|null the budget's dimension view (a cost centre, project or branch), null for the account-level budget */
    protected function scope(Request $request): ?array
    {
        $v = $request->validate(['dimension' => 'nullable|in:cost_center,project,branch', 'dimension_id' => 'nullable|integer']);
        if (empty($v['dimension']) || empty($v['dimension_id'])) {
            return null;
        }
        $model = ['cost_center' => CostCenter::class, 'project' => Project::class, 'branch' => Branch::class][$v['dimension']];
        abort_unless($model::whereKey($v['dimension_id'])->exists(), 422, 'البعد غير موجود');

        return [$v['dimension'], (int) $v['dimension_id']];
    }

    protected function scopedLines(Budget $budget, ?array $scope)
    {
        $q = BudgetLine::where('budget_id', $budget->id);

        return $scope
            ? $q->where(BudgetService::DIMENSIONS[$scope[0]], $scope[1])
            : $q->whereNull('cost_center_id')->whereNull('project_id')->whereNull('branch_id');
    }

    public function updateLines(Request $request, Budget $budget)
    {
        if ($budget->status === 'approved') {
            return back()->with('error', 'الموازنة معتمدة ولا يمكن تعديلها.');
        }

        $validated = $request->validate([
            'lines' => 'present|array',
            'lines.*.account_id' => 'required|tenant_exists:accounts,id',
            'lines.*.period' => 'required|integer',
            'lines.*.amount' => 'required|numeric',
            'approve' => 'boolean',
        ]);
        $scope = $this->scope($request);

        DB::transaction(function () use ($validated, $budget, $request, $scope) {
            // Only the lines of the view being saved are replaced (the account-level budget or one dimension value).
            $this->scopedLines($budget, $scope)->delete();
            $dimension = $scope ? [BudgetService::DIMENSIONS[$scope[0]] => $scope[1]] : [];

            foreach (array_chunk($validated['lines'], 500) as $chunk) {
                foreach ($chunk as $line) {
                    if ((float) $line['amount'] == 0.0) {
                        continue;
                    }
                    BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $line['account_id'], 'period' => $line['period'], 'amount' => $line['amount']] + $dimension);
                }
            }

            if ($request->boolean('approve')) {
                $budget->update(['status' => 'approved']);
            }
        });

        return back()->with('success', 'تم حفظ الموازنة');
    }
}

<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Services\Accounting\AccountingReports;
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
    public function show(Budget $budget, AccountingReports $reports): Response
    {
        $budget->load('fiscalYear');
        $year = $budget->fiscalYear;
        $months = [];
        for ($m = Carbon::parse($year->start_date); $m->lte($year->end_date); $m->addMonth()) {
            $months[] = (int) $m->format('Ym');
        }

        $accounts = Account::whereIn('type', ['revenue', 'expense'])->where('is_parent', false)->orderBy('code')->get(['id', 'code', 'name_ar', 'type']);

        $lines = BudgetLine::where('budget_id', $budget->id)->get()
            ->groupBy('account_id')
            ->map(fn ($rows) => $rows->pluck('amount', 'period')->map(fn ($v) => (float) $v));

        $actualByMonth = DB::table('account_balances')
            ->where('company_id', $budget->company_id)
            ->whereBetween('period', [min($months), max($months)])
            ->whereIn('account_id', $accounts->pluck('id'))
            ->get(['account_id', 'period', 'debit', 'credit'])
            ->groupBy('account_id');

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
        ]);
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

        DB::transaction(function () use ($validated, $budget, $request) {
            BudgetLine::where('budget_id', $budget->id)->delete();

            foreach (array_chunk($validated['lines'], 500) as $chunk) {
                foreach ($chunk as $line) {
                    if ((float) $line['amount'] == 0.0) {
                        continue;
                    }
                    BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $line['account_id'], 'period' => $line['period'], 'amount' => $line['amount']]);
                }
            }

            if ($request->boolean('approve')) {
                $budget->update(['status' => 'approved']);
            }
        });

        return back()->with('success', 'تم حفظ الموازنة');
    }
}

<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrialBalanceController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = Account::where('is_parent', false)
            ->with(['journalLines' => function ($q) use ($request) {
                if ($request->filled('start_date')) {
                    $q->whereHas('journalEntry', fn($sub) => $sub->where('date', '>=', $request->input('start_date')));
                }
                if ($request->filled('end_date')) {
                    $q->whereHas('journalEntry', fn($sub) => $sub->where('date', '<=', $request->input('end_date')));
                }
            }])
            ->orderBy('code')
            ->get();

        $rows = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $acc) {
            $sumDebit = (float) $acc->journalLines->sum('debit');
            $sumCredit = (float) $acc->journalLines->sum('credit');

            // Net balance
            $netDebit = 0;
            $netCredit = 0;

            if ($acc->nature === 'debit') {
                $net = $sumDebit - $sumCredit;
                if ($net >= 0) {
                    $netDebit = $net;
                } else {
                    $netCredit = abs($net);
                }
            } else {
                $net = $sumCredit - $sumDebit;
                if ($net >= 0) {
                    $netCredit = $net;
                } else {
                    $netDebit = abs($net);
                }
            }

            $totalDebit += $sumDebit;
            $totalCredit += $sumCredit;

            $rows[] = [
                'id' => $acc->id,
                'code' => $acc->code,
                'name_ar' => $acc->name_ar,
                'type' => $acc->type,
                'nature' => $acc->nature,
                'total_debit' => $sumDebit,
                'total_credit' => $sumCredit,
                'ending_debit' => $netDebit,
                'ending_credit' => $netCredit,
            ];
        }

        $isBalanced = abs($totalDebit - $totalCredit) < 0.01;

        return Inertia::render('Accounting/TrialBalance/Index', [
            'rows' => $rows,
            'summary' => [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'difference' => abs($totalDebit - $totalCredit),
                'is_balanced' => $isBalanced,
            ],
            'filters' => [
                'start_date' => $request->input('start_date', ''),
                'end_date' => $request->input('end_date', ''),
            ],
        ]);
    }
}

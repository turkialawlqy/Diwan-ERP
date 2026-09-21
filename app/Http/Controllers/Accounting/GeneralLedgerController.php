<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeneralLedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = Account::where('is_parent', false)->orderBy('code')->get(['id', 'code', 'name_ar', 'nature', 'balance']);
        $selectedAccountId = $request->input('account_id') ?? $accounts->first()?->id;

        $selectedAccount = null;
        $lines = [];
        $runningBalance = 0;
        $totalDebit = 0;
        $totalCredit = 0;

        if ($selectedAccountId) {
            $selectedAccount = Account::find($selectedAccountId);

            $query = JournalEntryLine::with('journalEntry')
                ->where('account_id', $selectedAccountId)
                ->whereHas('journalEntry', function ($q) use ($request) {
                    if ($request->filled('start_date')) {
                        $q->where('date', '>=', $request->input('start_date'));
                    }
                    if ($request->filled('end_date')) {
                        $q->where('date', '<=', $request->input('end_date'));
                    }
                })
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->orderBy('journal_entries.date', 'asc')
                ->select('journal_entry_lines.*');

            $fetchedLines = $query->get();

            foreach ($fetchedLines as $line) {
                $totalDebit += (float) $line->debit;
                $totalCredit += (float) $line->credit;

                if ($selectedAccount->nature === 'debit') {
                    $runningBalance += ((float) $line->debit - (float) $line->credit);
                } else {
                    $runningBalance += ((float) $line->credit - (float) $line->debit);
                }

                $lines[] = [
                    'id' => $line->id,
                    'date' => $line->journalEntry->date->format('Y-m-d'),
                    'entry_number' => $line->journalEntry->entry_number,
                    'reference' => $line->journalEntry->reference,
                    'description' => $line->description ?: $line->journalEntry->description,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'running_balance' => $runningBalance,
                ];
            }
        }

        return Inertia::render('Accounting/Ledger/Index', [
            'accounts' => $accounts,
            'selectedAccount' => $selectedAccount,
            'lines' => $lines,
            'summary' => [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'net_balance' => $runningBalance,
            ],
            'filters' => [
                'account_id' => $selectedAccountId,
                'start_date' => $request->input('start_date', ''),
                'end_date' => $request->input('end_date', ''),
            ],
        ]);
    }
}

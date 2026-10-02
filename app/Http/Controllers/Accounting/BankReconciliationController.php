<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingReports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank reconciliation: tick the ledger lines that appear on the bank
 * statement until the cleared balance equals the statement balance.
 */
class BankReconciliationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Accounting/Reconciliations/Index', [
            'reconciliations' => BankReconciliation::with('bankAccount:id,name')->orderByDesc('statement_date')->paginate(20),
            'bankAccounts' => BankAccount::where('is_active', true)->whereNotNull('account_id')->get(['id', 'name', 'type']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bank_account_id' => 'required|tenant_exists:bank_accounts,id',
            'statement_date' => 'required|date',
            'statement_balance' => 'required|numeric',
        ]);

        if (BankReconciliation::where('bank_account_id', $validated['bank_account_id'])->where('status', 'draft')->exists()) {
            return back()->withErrors(['bank_account_id' => 'توجد تسوية مفتوحة لهذا الحساب. أكملها أولاً.']);
        }

        $reconciliation = BankReconciliation::create(array_merge($validated, ['status' => 'draft']));

        return redirect()->route('accounting.reconciliations.show', $reconciliation->id);
    }

    public function show(BankReconciliation $reconciliation, AccountingReports $reports): Response
    {
        $reconciliation->load('bankAccount');
        $accountId = $reconciliation->bankAccount->account_id;
        $account = Account::findOrFail($accountId);

        // Lines up to the statement date that are not reconciled yet, plus the
        // lines ticked in this reconciliation.
        $lines = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $reconciliation->company_id)
            ->where('l.account_id', $accountId)
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->where('l.date', '<=', $reconciliation->statement_date->toDateString().' 23:59:59')
            ->where(fn ($q) => $q->whereNull('l.reconciliation_id')->orWhere('l.reconciliation_id', $reconciliation->id))
            ->orderBy('l.date')
            ->limit(2000)
            ->get(['l.id', 'l.date', 'l.debit', 'l.credit', 'l.description', 'l.reconciliation_id', 'e.entry_number', 'e.reference']);

        // Balance of already reconciled lines (previous reconciliations).
        $previouslyCleared = DB::table('journal_entry_lines')
            ->where('account_id', $accountId)
            ->whereNotNull('reconciliation_id')
            ->where('reconciliation_id', '!=', $reconciliation->id)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')
            ->value('net');

        $bookBalance = collect($reports->totalsBetween(null, Carbon::parse($reconciliation->statement_date), [$accountId]))->sum(fn ($t) => $t['debit'] - $t['credit']);

        return Inertia::render('Accounting/Reconciliations/Show', [
            'reconciliation' => $reconciliation,
            'lines' => $lines->map(fn ($l) => array_merge((array) $l, ['date' => substr((string) $l->date, 0, 10), 'debit' => (float) $l->debit, 'credit' => (float) $l->credit])),
            'previouslyCleared' => round((float) $previouslyCleared, 2),
            'bookBalance' => round($bookBalance, 2),
            'account' => $account->only(['id', 'code', 'name_ar']),
        ]);
    }

    /**
     * Save the ticked lines (and optionally complete the reconciliation).
     */
    public function update(Request $request, BankReconciliation $reconciliation)
    {
        if ($reconciliation->status === 'completed') {
            return back()->with('error', 'التسوية مكتملة ولا يمكن تعديلها.');
        }

        $validated = $request->validate([
            'line_ids' => 'present|array',
            'line_ids.*' => 'integer',
            'complete' => 'boolean',
        ]);

        $accountId = $reconciliation->bankAccount->account_id;

        DB::transaction(function () use ($validated, $reconciliation, $accountId, $request) {
            DB::table('journal_entry_lines')
                ->where('reconciliation_id', $reconciliation->id)
                ->update(['reconciliation_id' => null, 'reconciled_at' => null]);

            DB::table('journal_entry_lines')
                ->where('company_id', $reconciliation->company_id)
                ->where('account_id', $accountId)
                ->whereNull('reconciliation_id')
                ->whereIn('id', $validated['line_ids'])
                ->update(['reconciliation_id' => $reconciliation->id, 'reconciled_at' => now()]);

            $cleared = (float) DB::table('journal_entry_lines')
                ->where('account_id', $accountId)
                ->whereNotNull('reconciliation_id')
                ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')
                ->value('net');

            $complete = $request->boolean('complete');
            if ($complete && abs($cleared - (float) $reconciliation->statement_balance) >= 0.01) {
                throw ValidationException::withMessages([
                    'line_ids' => 'لا يمكن إكمال التسوية: الرصيد المسوّى ('.number_format($cleared, 2).') لا يساوي رصيد كشف البنك ('.number_format((float) $reconciliation->statement_balance, 2).').',
                ]);
            }

            $reconciliation->update([
                'reconciled_total' => round($cleared, 2),
                'status' => $complete ? 'completed' : 'draft',
                'completed_by' => $complete ? auth()->id() : null,
                'completed_at' => $complete ? now() : null,
            ]);
        });

        return back()->with('success', $request->boolean('complete') ? 'تم إكمال التسوية البنكية' : 'تم حفظ التسوية');
    }
}

<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    public function index(): Response
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? auth()->user()?->company_id;

        $accounts = BankAccount::where('company_id', $companyId)
            ->where('is_active', true)
            ->get();

        $transfers = JournalEntry::with('lines.account')
            ->where('company_id', $companyId)
            ->where('reference', 'like', 'TRF-%')
            ->latest('date')
            ->latest('id')
            ->paginate(15);

        $stats = [
            'total_transfers_count' => JournalEntry::where('company_id', $companyId)->where('reference', 'like', 'TRF-%')->count(),
            'total_transfers_amount' => JournalEntry::where('company_id', $companyId)->where('reference', 'like', 'TRF-%')->sum('total_debit'),
        ];

        return Inertia::render('Finance/Transfers/Index', [
            'accounts' => $accounts,
            'transfers' => $transfers,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? auth()->user()?->company_id;

        $validated = $request->validate([
            'from_account_id' => 'required|exists:bank_accounts,id|different:to_account_id',
            'to_account_id' => 'required|exists:bank_accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'transfer_date' => 'required|date',
            'reference' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($validated, $companyId) {
            $from = BankAccount::where('id', $validated['from_account_id'])
                ->where('company_id', $companyId)
                ->firstOrFail();

            $to = BankAccount::where('id', $validated['to_account_id'])
                ->where('company_id', $companyId)
                ->firstOrFail();

            $amount = (float) $validated['amount'];

            if ($from->current_balance < $amount) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'رصيد الحساب المصدر غير كافٍ لإتمام عملية التحويل.',
                ]);
            }

            // Update balances
            $from->decrement('current_balance', $amount);
            $to->increment('current_balance', $amount);

            // Generate Journal Entry
            // Generate Journal Entry number using MAX to avoid race conditions
            $lastEntry = JournalEntry::where('entry_number', 'like', 'JV-' . date('y') . '-%')
                ->orderByRaw('CAST(SUBSTRING_INDEX(entry_number, "-", -1) AS UNSIGNED) DESC')
                ->value('entry_number');
            $nextEntrySeq = $lastEntry
                ? ((int) substr($lastEntry, strrpos($lastEntry, '-') + 1)) + 1
                : 1;
            $entryNumber = sprintf("JV-%s-%05d", date('y'), $nextEntrySeq);
            $transferRef = 'TRF-' . ($validated['reference'] ?? sprintf("%s%04d", date('ymd'), $nextEntrySeq));

            $desc = "تحويل مالي من [{$from->name}] إلى [{$to->name}]" . ($validated['notes'] ? " - " . $validated['notes'] : '');

            // Only post a GL journal entry when BOTH sides have a mapped chart-of-accounts
            // account. Creating it with just one leg would leave an unbalanced entry
            // (debit without a matching credit, or vice versa) in the ledger. The bank
            // account balances above are updated unconditionally either way.
            if ($to->account_id && $from->account_id) {
                $journal = JournalEntry::create([
                    'company_id' => $companyId,
                    'entry_number' => $entryNumber,
                    'date' => $validated['transfer_date'],
                    'reference' => $transferRef,
                    'description' => $desc,
                    'total_debit' => $amount,
                    'total_credit' => $amount,
                    'status' => 'posted',
                    'created_by' => auth()->id(),
                ]);

                JournalEntryLine::create([
                    'journal_entry_id' => $journal->id,
                    'account_id' => $to->account_id,
                    'description' => "استلام تحويل إلى {$to->name}",
                    'debit' => $amount,
                    'credit' => 0,
                ]);

                JournalEntryLine::create([
                    'journal_entry_id' => $journal->id,
                    'account_id' => $from->account_id,
                    'description' => "تحويل صادر من {$from->name}",
                    'debit' => 0,
                    'credit' => $amount,
                ]);
            }
        });

        return redirect()->route('finance.transfers.index')
            ->with('success', 'تم تسجيل التحويل المالي وتحديث الأرصدة بنجاح');
    }
}

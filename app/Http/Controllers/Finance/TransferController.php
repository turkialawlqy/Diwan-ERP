<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Services\Accounting\DocumentPoster;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    public function index(): Response
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? auth()->user()?->company_id;

        $accounts = BankAccount::withLedgerBalances(BankAccount::where('company_id', $companyId)
            ->where('is_active', true)
            ->get());

        $transfers = JournalEntry::with('lines.account')
            ->where('source_type', 'transfer')
            ->whereNull('reversal_of_id')
            ->latest('date')
            ->latest('id')
            ->paginate(15);

        $stats = [
            'total_transfers_count' => JournalEntry::where('source_type', 'transfer')->whereNull('reversal_of_id')->count(),
            'total_transfers_amount' => JournalEntry::where('source_type', 'transfer')->whereNull('reversal_of_id')->sum('total_debit'),
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
            'from_account_id' => 'required|tenant_exists:bank_accounts,id|different:to_account_id',
            'to_account_id' => 'required|tenant_exists:bank_accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'transfer_date' => 'required|date',
            'reference' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($validated, $companyId) {
            // Lock both accounts (in id order to avoid deadlocks) so concurrent
            // transfers cannot overdraw the source account.
            $locked = BankAccount::whereIn('id', [$validated['from_account_id'], $validated['to_account_id']])
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $from = $locked->get($validated['from_account_id']) ?? abort(404);
            $to = $locked->get($validated['to_account_id']) ?? abort(404);

            $amount = (float) $validated['amount'];

            if ($from->ledgerBalance() < $amount - 0.005) {
                throw ValidationException::withMessages([
                    'amount' => 'رصيد الحساب المصدر غير كافٍ لإتمام عملية التحويل.',
                ]);
            }

            // Update balances
            $from->decrement('current_balance', $amount);
            $to->increment('current_balance', $amount);

            $transferRef = $validated['reference'] ?? $this->nextNumber('transfer', $validated['transfer_date']);
            $desc = "تحويل مالي من [{$from->name}] إلى [{$to->name}]".(! empty($validated['notes']) ? ' - '.$validated['notes'] : '');

            app(DocumentPoster::class)->transfer($from, $to, $amount, $validated['transfer_date'], $transferRef, $desc);
        });

        return redirect()->route('finance.transfers.index')
            ->with('success', 'تم تسجيل التحويل المالي وتحديث الأرصدة بنجاح');
    }
}

<?php

namespace App\Services\Accounting;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Compares the stored customer / supplier balances (the figure credit limits use)
 * with what the ledger says, and optionally rewrites them from the ledger.
 */
class PartyBalanceReconciler
{
    public function __construct(protected PartyAccounts $parties, protected AccountMap $map) {}

    /** @return array<int, array{kind: string, id: int, name: string, stored: float, ledger: float, difference: float}> */
    public function differences(int $companyId, bool $fix = false): array
    {
        $rows = [];
        foreach (['customer' => [Customer::class, 1, $this->parties->receivableAccountIds($companyId)], 'supplier' => [Supplier::class, -1, array_filter([$this->map->find('payables', $companyId)])]] as $kind => [$model, $sign, $accounts]) {
            $ledger = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.company_id', $companyId)->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
                ->whereIn('l.account_id', $accounts)->where('l.party_type', $kind)
                ->groupBy('l.party_id')->selectRaw('l.party_id, SUM(l.debit - l.credit) as balance')->pluck('balance', 'party_id');

            $model::withoutGlobalScopes()->where('company_id', $companyId)->each(function ($party) use ($ledger, $sign, $kind, $fix, &$rows) {
                $stored = round((float) $party->current_balance, 2);
                $book = round($sign * (float) ($ledger[$party->id] ?? 0), 2);
                if (abs($stored - $book) < 0.005) {
                    return;
                }
                $rows[] = ['kind' => $kind, 'id' => $party->id, 'name' => (string) $party->name, 'stored' => $stored, 'ledger' => $book, 'difference' => round($stored - $book, 2)];
                if ($fix) {
                    $party->forceFill(['current_balance' => $book])->saveQuietly();
                }
            });
        }

        return $rows;
    }
}

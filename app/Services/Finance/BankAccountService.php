<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\BankAccount;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DocumentPoster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Creates a bank / cash box with its own ledger account and opening balance (form and Excel import). */
class BankAccountService
{
    public function create(array $validated, ?Carbon $date = null): BankAccount
    {
        return DB::transaction(function () use ($validated, $date) {
            $box = BankAccount::create([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'bank_name' => $validated['bank_name'] ?? null,
                'account_number' => $validated['account_number'] ?? null,
                'iban' => $validated['iban'] ?? null,
                'currency' => $validated['currency'],
                'opening_balance' => $validated['opening_balance'],
                'current_balance' => $validated['opening_balance'],
                // Every box gets its own ledger account (needed for statements and reconciliation).
                'account_id' => $validated['account_id'] ?? $this->createLedgerAccount($validated['name'], $validated['type'])->id,
                'is_active' => true,
            ]);

            app(DocumentPoster::class)->openingBalance(
                $box,
                ['account_id' => $box->account_id, 'side' => 'debit'],
                (float) $validated['opening_balance'],
                $date ?? now(),
                'رصيد افتتاحي - '.$box->name,
            );

            return $box;
        });
    }

    /**
     * Leaf ledger account for a new cash box / bank under the cash group.
     */
    public function createLedgerAccount(string $name, string $type): Account
    {
        $map = app(AccountMap::class);
        $default = Account::find($map->id($type === 'cash' ? 'default_cash' : 'default_bank'));
        $parent = $default?->parent;

        $prefix = $parent?->code ?? '111';
        $siblings = Account::where('parent_id', $parent?->id)->pluck('code');
        $next = 1;
        while ($siblings->contains($prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT)) || Account::where('code', $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT))->exists()) {
            $next++;
        }

        return Account::create([
            'parent_id' => $parent?->id,
            'code' => $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT),
            'name_ar' => $name,
            'type' => 'asset',
            'nature' => 'debit',
            'is_parent' => false,
            'balance' => 0,
            'is_active' => true,
            'cash_flow_category' => null,
        ]);
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use Auditable, BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'account_id',
        'name',
        'type',
        'bank_name',
        'account_number',
        'iban',
        'currency',
        'opening_balance',
        'current_balance',
        'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * The box's balance as the books show it: its ledger account. The stored
     * current_balance is only moved by some screens (invoices and vouchers post
     * to the ledger alone), so it is kept as a fallback for unlinked boxes.
     */
    public function ledgerBalance(): float
    {
        if (! $this->account_id) {
            return (float) $this->current_balance;
        }

        return (float) Account::withoutGlobalScopes()->whereKey($this->account_id)->value('balance');
    }

    /** Replace current_balance with the ledger balance for display. */
    public static function withLedgerBalances(iterable $boxes): iterable
    {
        $balances = Account::withoutGlobalScopes()->whereIn('id', collect($boxes)->pluck('account_id')->filter()->unique())->pluck('balance', 'id');
        foreach ($boxes as $box) {
            if ($box->account_id && $balances->has($box->account_id)) {
                $box->current_balance = (float) $balances[$box->account_id];
            }
        }

        return $boxes;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function revenues(): HasMany
    {
        return $this->hasMany(Revenue::class);
    }
}

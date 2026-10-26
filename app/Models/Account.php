<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use Auditable, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name_ar',
        'name_en',
        'type',
        'nature',
        'is_parent',
        'balance',
        'is_active',
        'is_system',
        'cash_flow_category',
        'requires_cost_center',
    ];

    protected $casts = [
        'is_parent' => 'boolean',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'requires_cost_center' => 'boolean',
        'balance' => 'decimal:2',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }

    /**
     * Debit-nature accounts (assets, expenses) grow with debits.
     */
    public function isDebitNature(): bool
    {
        return $this->nature === 'debit';
    }

    /**
     * Signed balance from debit / credit totals according to the account nature.
     */
    public function signedBalance(float $debit, float $credit): float
    {
        return round($this->isDebitNature() ? $debit - $credit : $credit - $debit, 2);
    }
}

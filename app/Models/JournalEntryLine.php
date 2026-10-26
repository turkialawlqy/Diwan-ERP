<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'cost_center_id',
        'party_type',
        'party_id',
        'date',
        'description',
        'debit',
        'credit',
        'reconciliation_id',
        'reconciled_at',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'date' => 'date',
        'reconciled_at' => 'datetime',
    ];

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Parent document the line inherits its company from outside a tenant context.
     */
    public function tenantParent(): ?Model
    {
        return $this->journalEntry;
    }
}

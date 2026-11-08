<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectAllocationRun extends Model
{
    use Auditable, BelongsToCompany;

    public const BASES = ['revenue', 'direct_cost', 'hours'];

    protected $fillable = ['company_id', 'branch_id', 'number', 'period_from', 'period_to', 'basis', 'pool_account_ids', 'pool_amount', 'status', 'journal_entry_id', 'notes', 'created_by'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'pool_account_ids' => 'array', 'pool_amount' => 'decimal:2'];

    public function lines(): HasMany
    {
        return $this->hasMany(ProjectAllocationLine::class, 'run_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}

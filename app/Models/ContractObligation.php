<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractObligation extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'contract_id', 'title', 'kind', 'due_date', 'amount', 'status', 'done_at', 'done_by', 'notes', 'reminder_state'];

    protected $casts = ['due_date' => 'date', 'amount' => 'decimal:2', 'done_at' => 'datetime'];

    public const KINDS = ['payment' => 'دفعة', 'delivery' => 'تسليم', 'deliverable' => 'مخرَج', 'milestone' => 'مرحلة', 'other' => 'أخرى'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->lt(today());
    }
}

<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'document_type', 'subject_key', 'description', 'amount', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['amount' => 'decimal:2', 'decided_at' => 'datetime'];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

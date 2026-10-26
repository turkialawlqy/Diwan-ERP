<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cheque extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'direction', 'cheque_number', 'bank_name', 'bank_account_id', 'party_type', 'party_id', 'party_name',
        'amount', 'issue_date', 'due_date', 'status', 'notes', 'history', 'created_by', 'source_type', 'source_id', 'invoice_id', 'purchase_invoice_id',
    ];

    protected $casts = ['issue_date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2', 'history' => 'array'];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}

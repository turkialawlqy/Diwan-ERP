<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'account_id', 'period', 'debit', 'credit'];

    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

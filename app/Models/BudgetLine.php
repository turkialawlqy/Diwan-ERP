<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'budget_id', 'account_id', 'cost_center_id', 'period', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

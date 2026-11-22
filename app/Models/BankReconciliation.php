<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliation extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'bank_account_id', 'statement_date', 'statement_balance', 'book_balance', 'reconciled_total', 'status', 'completed_by', 'completed_at'];

    protected $casts = ['statement_date' => 'date', 'completed_at' => 'datetime', 'statement_balance' => 'decimal:2', 'book_balance' => 'decimal:2', 'reconciled_total' => 'decimal:2'];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * A reconciliation has no branch of its own: as in the index, a route finds it only with a bank the branch scope
     * shows (archived ones too). Another branch's gets the same 404 as one that does not exist, whatever the request
     * carries, so neither its existence nor its status shows.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->resolveRouteBindingQuery($this, $value, $field)
            ->whereHas('bankAccount', fn ($q) => $q->withTrashed())
            ->first();
    }
}

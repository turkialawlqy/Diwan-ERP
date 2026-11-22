<?php

namespace App\Models;

use App\Services\Accounting\AccountMap;
use App\Services\TenantManager;
use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name', 'description', 'account_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Accounts a group's receivables may go to (CustomerGroupController refuses the others): active asset leaves that
     * need no cost center (receivables are posted without one) and are neither a box's ledger nor an AccountMap role
     * other than receivables. A group keeps showing its own.
     */
    public static function accountOptions(?self $group = null): Builder
    {
        $roles = collect(app(AccountMap::class)->all())->except('receivables')->filter()->map(fn ($id) => (int) $id)->all();
        $ledgers = BankAccount::withoutGlobalScopes()->where('company_id', app(TenantManager::class)->getTenantId())->whereNotNull('account_id')->pluck('account_id')->all();

        return Account::where('type', 'asset')->where('is_parent', false)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->where('requires_cost_center', false)->whereNotIn('id', array_merge($roles, $ledgers)))
                ->when($group?->account_id, fn ($q, $id) => $q->orWhere('id', $id)));
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}

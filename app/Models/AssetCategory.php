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

class AssetCategory extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name_ar', 'name_en', 'useful_life_months', 'depreciation_method', 'declining_rate', 'asset_account_id', 'accumulated_account_id', 'expense_account_id'];

    /** The category's accounts: field => [the account type it takes, the AccountMap role it defaults to]. */
    public const ACCOUNTS = [
        'asset_account_id' => ['asset', 'fixed_assets'],
        'accumulated_account_id' => ['asset', 'accumulated_depreciation'],
        'expense_account_id' => ['expense', 'depreciation_expense'],
    ];

    /**
     * Accounts $field may take (FixedAssetController refuses the others): active leaves of its type that are no box's
     * ledger nor one of foreignRoles().
     */
    public static function accountOptions(string $field): Builder
    {
        $ledgers = BankAccount::withoutGlobalScopes()->where('company_id', app(TenantManager::class)->getTenantId())->whereNotNull('account_id')->pluck('account_id')->all();

        return Account::where('type', self::ACCOUNTS[$field][0])->where('is_parent', false)->where('is_active', true)
            ->whereNotIn('id', array_merge($ledgers, self::foreignRoles($field)));
    }

    /**
     * AccountMap roles $field may not take. Every purchase, depreciation run and disposal of the category's assets, in
     * every branch, posts to its asset and accumulated-depreciation accounts: neither is another role's account (default
     * cash or bank, receivables, input VAT...). Their own default role is fine; the expense account is not concerned.
     *
     * @return array<int, int>
     */
    public static function foreignRoles(string $field): array
    {
        if ($field === 'expense_account_id') {
            return [];
        }

        return collect(app(AccountMap::class)->all())->except(self::ACCOUNTS[$field][1])->filter()->map(fn ($id) => (int) $id)->values()->all();
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }
}

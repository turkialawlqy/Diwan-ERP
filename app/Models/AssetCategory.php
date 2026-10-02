<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name_ar', 'name_en', 'useful_life_months', 'depreciation_method', 'declining_rate', 'asset_account_id', 'accumulated_account_id', 'expense_account_id'];

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

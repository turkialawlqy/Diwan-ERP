<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDepreciation extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'fixed_asset_id', 'period', 'amount', 'journal_entry_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}

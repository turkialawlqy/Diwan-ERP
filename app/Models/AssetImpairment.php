<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetImpairment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'fixed_asset_id', 'date', 'amount', 'reason', 'journal_entry_id', 'created_by'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}

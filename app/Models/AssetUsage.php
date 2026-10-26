<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Units an asset produced or ran in a month, for units-of-production depreciation. */
class AssetUsage extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'fixed_asset_id', 'period', 'units'];

    protected $casts = ['units' => 'decimal:2'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'asset_category_id', 'branch_id', 'cost_center_id', 'code', 'name_ar', 'name_en', 'serial_number',
        'location', 'acquisition_date', 'depreciation_start_date', 'cost', 'salvage_value', 'useful_life_months',
        'depreciation_method', 'accumulated_depreciation', 'last_depreciation_date', 'status', 'disposal_date',
        'disposal_amount', 'acquisition_entry_id', 'disposal_entry_id', 'notes', 'opening_accumulated', 'acquisition_vat', 'disposal_vat',
        'accumulated_impairment', 'total_units', 'unit_label',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'depreciation_start_date' => 'date',
        'last_depreciation_date' => 'date',
        'disposal_date' => 'date',
        'cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'disposal_amount' => 'decimal:2',
        'opening_accumulated' => 'decimal:2',
        'acquisition_vat' => 'decimal:2',
        'disposal_vat' => 'decimal:2',
        'accumulated_impairment' => 'decimal:2',
        'total_units' => 'decimal:2',
    ];

    protected $appends = ['book_value'];

    public function getBookValueAttribute(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation - (float) $this->accumulated_impairment, 2);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function impairments(): HasMany
    {
        return $this->hasMany(AssetImpairment::class)->orderBy('date');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AssetUsage::class)->orderBy('period');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class)->orderBy('period');
    }
}

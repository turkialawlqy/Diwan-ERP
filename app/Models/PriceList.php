<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'is_default', 'is_active', 'valid_from', 'valid_to'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date'];

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}

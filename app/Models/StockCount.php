<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'count_number', 'warehouse_id', 'count_date', 'count_type', 'status', 'notes', 'approved_by', 'approved_at'];

    protected $casts = ['count_date' => 'date', 'approved_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

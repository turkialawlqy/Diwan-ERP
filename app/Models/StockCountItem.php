<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'stock_count_id', 'product_id', 'system_quantity', 'counted_quantity', 'unit_cost'];

    protected $casts = ['system_quantity' => 'decimal:4', 'counted_quantity' => 'decimal:4', 'unit_cost' => 'decimal:4'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function tenantParent(): ?Model
    {
        return $this->count;
    }
}

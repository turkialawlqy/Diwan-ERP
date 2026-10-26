<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBatch extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'product_id', 'warehouse_id', 'batch_number', 'manufacture_date', 'expiry_date', 'quantity'];

    protected $casts = ['manufacture_date' => 'date', 'expiry_date' => 'date', 'quantity' => 'decimal:4'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

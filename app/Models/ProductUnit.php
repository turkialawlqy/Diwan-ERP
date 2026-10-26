<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'product_id', 'unit_id', 'factor', 'barcode', 'selling_price', 'purchase_price'];

    protected $casts = ['factor' => 'decimal:6', 'selling_price' => 'decimal:4', 'purchase_price' => 'decimal:4'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

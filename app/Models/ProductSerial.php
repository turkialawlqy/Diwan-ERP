<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSerial extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'product_id', 'warehouse_id', 'serial_number', 'status', 'last_source_type', 'last_source_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

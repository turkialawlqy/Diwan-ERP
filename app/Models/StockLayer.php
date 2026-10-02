<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StockLayer extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'product_id', 'warehouse_id', 'movement_id', 'date', 'quantity', 'remaining', 'unit_cost'];

    protected $casts = ['date' => 'date', 'quantity' => 'decimal:4', 'remaining' => 'decimal:4', 'unit_cost' => 'decimal:4'];
}

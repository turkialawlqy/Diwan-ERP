<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'stock_transfer_id', 'product_id', 'batch_id', 'quantity', 'received_quantity', 'unit_cost'];

    protected $casts = ['quantity' => 'decimal:4', 'received_quantity' => 'decimal:4', 'unit_cost' => 'decimal:4'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function tenantParent(): ?Model
    {
        return $this->transfer;
    }
}

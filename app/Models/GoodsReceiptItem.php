<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'goods_receipt_id', 'purchase_order_item_id', 'product_id', 'item_name', 'quantity', 'invoiced_quantity', 'unit_cost', 'batch_number', 'expiry_date', 'serials'];

    protected $casts = ['quantity' => 'decimal:4', 'invoiced_quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'expiry_date' => 'date', 'serials' => 'array'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->receipt;
    }
}

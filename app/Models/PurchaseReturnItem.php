<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'purchase_return_id', 'purchase_invoice_item_id', 'product_id', 'item_name', 'quantity', 'unit_price', 'tax_rate', 'tax_amount', 'total', 'serials'];

    protected $casts = ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2', 'serials' => 'array'];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->purchaseReturn;
    }
}

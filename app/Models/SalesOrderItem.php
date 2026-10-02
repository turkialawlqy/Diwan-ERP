<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'sales_order_id', 'product_id', 'unit_id', 'item_name', 'description', 'quantity', 'delivered_quantity', 'invoiced_quantity', 'unit_price', 'discount', 'tax_rate', 'tax_amount', 'total'];

    protected $casts = ['quantity' => 'decimal:4', 'delivered_quantity' => 'decimal:4', 'invoiced_quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->order;
    }
}

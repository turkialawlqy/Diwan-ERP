<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryNoteItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'delivery_note_id', 'sales_order_item_id', 'product_id', 'item_name', 'quantity', 'invoiced_quantity', 'unit_price', 'tax_rate', 'unit_cost', 'serials'];

    protected $casts = ['quantity' => 'decimal:4', 'invoiced_quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'unit_cost' => 'decimal:4', 'serials' => 'array'];

    public function note(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->note;
    }
}

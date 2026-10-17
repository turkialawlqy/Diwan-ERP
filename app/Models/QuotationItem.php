<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'quotation_id',
        'product_id',
        'item_name',
        'description',
        'quantity',
        'unit_price',
        'discount',
        'tax_rate',
        'tax_amount',
        'subtotal',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * Parent document the line inherits its company from outside a tenant context.
     */
    public function tenantParent(): ?Model
    {
        return $this->quotation;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

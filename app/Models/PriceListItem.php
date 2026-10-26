<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'price_list_id', 'product_id', 'min_quantity', 'price'];

    protected $casts = ['min_quantity' => 'decimal:4', 'price' => 'decimal:4'];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->priceList;
    }
}

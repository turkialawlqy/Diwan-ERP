<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'purchase_request_id', 'product_id', 'item_name', 'quantity', 'estimated_price'];

    protected $casts = ['quantity' => 'decimal:4', 'estimated_price' => 'decimal:4'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->request;
    }
}

<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressBillingItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'progress_billing_id', 'boq_item_id', 'previous_quantity', 'current_quantity', 'unit_price', 'amount'];

    protected $casts = ['previous_quantity' => 'decimal:4', 'current_quantity' => 'decimal:4'];

    public function billing(): BelongsTo
    {
        return $this->belongsTo(ProgressBilling::class, 'progress_billing_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(ProjectBoqItem::class, 'boq_item_id');
    }

    public function tenantParent(): ?Model
    {
        return $this->billing;
    }
}

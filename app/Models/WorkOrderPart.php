<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A part taken from stock for a work order (cost at issue, price billed). */
class WorkOrderPart extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'work_order_id', 'product_id', 'warehouse_id', 'quantity', 'unit_cost', 'unit_price', 'returned', 'issued_by'];

    protected $casts = ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'unit_price' => 'decimal:2', 'returned' => 'boolean'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}

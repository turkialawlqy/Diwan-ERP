<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceOrderItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'maintenance_order_id', 'product_id', 'warehouse_id', 'schedule_id', 'quantity', 'unit_cost', 'amount', 'issued_by'];

    protected $casts = ['quantity' => 'float', 'unit_cost' => 'float', 'amount' => 'float'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(MaintenanceOrder::class, 'maintenance_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}

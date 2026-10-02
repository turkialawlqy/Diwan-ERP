<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssemblyOrder extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'order_number', 'bom_id', 'warehouse_id', 'quantity', 'date', 'status', 'total_cost', 'created_by'];

    protected $casts = ['date' => 'date', 'quantity' => 'decimal:4', 'total_cost' => 'decimal:2'];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

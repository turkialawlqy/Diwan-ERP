<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'transfer_number', 'from_warehouse_id', 'to_warehouse_id', 'transfer_date', 'status', 'sent_at', 'received_at', 'sent_by', 'received_by', 'notes'];

    protected $casts = ['transfer_date' => 'date', 'sent_at' => 'datetime', 'received_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentMaintenanceSchedule extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'equipment_id', 'product_id', 'name', 'default_quantity', 'interval_hours', 'interval_km', 'interval_days',
        'warn_hours', 'warn_km', 'warn_days', 'last_done_date', 'last_done_hours', 'last_done_km', 'alert_state', 'is_active'];

    protected $casts = ['default_quantity' => 'float', 'interval_hours' => 'float', 'interval_km' => 'float', 'interval_days' => 'integer',
        'warn_hours' => 'float', 'warn_km' => 'float', 'warn_days' => 'integer', 'last_done_date' => 'date', 'last_done_hours' => 'float',
        'last_done_km' => 'float', 'is_active' => 'boolean'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceOrder extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'number', 'equipment_id', 'type', 'date', 'hours_reading', 'km_reading', 'technician', 'warehouse_id', 'status',
        'previous_status', 'parts_cost', 'notes', 'completed_at', 'created_by'];

    protected $casts = ['date' => 'date', 'hours_reading' => 'float', 'km_reading' => 'float', 'parts_cost' => 'float', 'completed_at' => 'datetime'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaintenanceOrderItem::class);
    }

    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(EquipmentMaintenanceSchedule::class, 'maintenance_order_schedules', 'maintenance_order_id', 'schedule_id')->withPivot('was_overdue');
    }
}

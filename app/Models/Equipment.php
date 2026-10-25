<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A machine, vehicle or tool that is rented out, charged to projects or
 * hired in from a supplier, with meters that drive periodic maintenance.
 */
class Equipment extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, SoftDeletes;

    protected $table = 'equipment';

    protected $fillable = ['company_id', 'branch_id', 'code', 'name', 'category', 'model', 'plate_number', 'serial_number', 'ownership', 'fixed_asset_id',
        'supplier_id', 'supplier_rate', 'supplier_rate_basis', 'meter_type', 'current_hours', 'current_km', 'last_reading_date', 'hourly_rate', 'daily_rate',
        'monthly_rate', 'internal_hourly_cost', 'status', 'cost_center_id', 'notes'];

    protected $casts = ['current_hours' => 'float', 'current_km' => 'float', 'supplier_rate' => 'float', 'hourly_rate' => 'float', 'daily_rate' => 'float',
        'monthly_rate' => 'float', 'internal_hourly_cost' => 'float', 'last_reading_date' => 'date'];

    public const STATUSES = ['available' => 'متاحة', 'rented' => 'مؤجّرة', 'internal' => 'في مشروع', 'maintenance' => 'في الصيانة', 'out_of_service' => 'خارج الخدمة'];

    public const BASES = ['hourly' => 'ساعة', 'daily' => 'يوم', 'monthly' => 'شهر'];

    public function usesHours(): bool
    {
        return in_array($this->meter_type, ['hours', 'both'], true);
    }

    public function usesKm(): bool
    {
        return in_array($this->meter_type, ['km', 'both'], true);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EquipmentMaintenanceSchedule::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(EquipmentReading::class);
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(EquipmentRental::class);
    }

    public function maintenanceOrders(): HasMany
    {
        return $this->hasMany(MaintenanceOrder::class);
    }

    public function billings(): HasMany
    {
        return $this->hasMany(EquipmentBilling::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function activeRental()
    {
        return $this->hasOne(EquipmentRental::class)->where('status', 'active')->latestOfMany();
    }

    /** Status implied by open maintenance and active contracts. */
    public function syncStatus(): void
    {
        if ($this->status === 'out_of_service') {
            return;
        }
        $status = match (true) {
            $this->maintenanceOrders()->where('status', 'open')->exists() => 'maintenance',
            $this->rentals()->where('status', 'active')->where('type', 'customer')->exists() => 'rented',
            $this->rentals()->where('status', 'active')->where('type', 'project')->exists() => 'internal',
            default => 'available',
        };
        if ($status !== $this->status) {
            $this->forceFill(['status' => $status])->save();
        }
    }
}

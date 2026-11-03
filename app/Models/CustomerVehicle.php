<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A customer's vehicle (the workshop's card): plate, odometer and the next service. */
class CustomerVehicle extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, SoftDeletes;

    protected $fillable = ['company_id', 'branch_id', 'customer_id', 'plate', 'make', 'model', 'year', 'vin', 'color', 'odometer', 'next_service_date', 'next_service_km', 'reminder_state', 'notes'];

    protected $casts = ['next_service_date' => 'date', 'odometer' => 'integer', 'next_service_km' => 'integer', 'reminder_state' => 'array'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'vehicle_id');
    }

    public function label(): string
    {
        return trim($this->plate.' — '.trim(($this->make ?? '').' '.($this->model ?? '').' '.($this->year ?? '')));
    }
}

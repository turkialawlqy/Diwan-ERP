<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Equipment rented to a customer (invoiced) or assigned to a project (charged at cost). */
class EquipmentRental extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'number', 'type', 'equipment_id', 'customer_id', 'project_id', 'billing_basis', 'rate', 'min_hours_per_day',
        'with_operator', 'tax_rate', 'start_date', 'end_date', 'billed_until', 'site', 'status', 'notes', 'created_by'];

    protected $casts = ['rate' => 'float', 'min_hours_per_day' => 'float', 'with_operator' => 'boolean', 'tax_rate' => 'float',
        'start_date' => 'date', 'end_date' => 'date', 'billed_until' => 'date'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function billings(): HasMany
    {
        return $this->hasMany(EquipmentBilling::class, 'rental_id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(EquipmentReading::class, 'rental_id');
    }
}

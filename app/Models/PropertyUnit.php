<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyUnit extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'property_id', 'unit_number', 'type', 'floor', 'area', 'rent_amount', 'status', 'notes'];

    protected $casts = ['rent_amount' => 'decimal:2', 'area' => 'decimal:2'];

    public const TYPES = ['apartment' => 'شقة', 'shop' => 'محل', 'office' => 'مكتب', 'warehouse' => 'مستودع', 'room' => 'غرفة'];

    public const STATUSES = ['vacant' => 'شاغرة', 'occupied' => 'مؤجَّرة', 'maintenance' => 'تحت الصيانة'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class)->latest('start_date');
    }

    public function activeLease(): ?Lease
    {
        return $this->leases()->where('status', 'active')->first();
    }

    public function label(): string
    {
        return ($this->property?->name ?? '').' — '.$this->unit_number;
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Something that can be booked: a staff member, room, piece of equipment or court. */
class BookingResource extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, SoftDeletes;

    protected $fillable = ['company_id', 'branch_id', 'name', 'type', 'employee_id', 'capacity', 'color', 'is_active', 'notes'];

    protected $casts = ['is_active' => 'boolean', 'capacity' => 'integer'];

    public const TYPES = ['staff' => 'موظف', 'room' => 'غرفة', 'equipment' => 'معدة', 'court' => 'ملعب / قاعة'];

    public function hours(): HasMany
    {
        return $this->hasMany(ResourceHour::class)->orderBy('weekday')->orderBy('start_time');
    }

    public function timeOffs(): HasMany
    {
        return $this->hasMany(ResourceTimeOff::class)->orderBy('starts_at');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(BookableService::class, 'bookable_service_resource');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}

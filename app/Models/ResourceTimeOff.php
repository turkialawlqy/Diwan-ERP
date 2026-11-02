<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A period when a resource cannot be booked (leave, maintenance). */
class ResourceTimeOff extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'booking_resource_id', 'starts_at', 'ends_at', 'reason'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
}

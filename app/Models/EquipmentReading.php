<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentReading extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'equipment_id', 'rental_id', 'date', 'hours_reading', 'km_reading', 'worked_hours', 'operator', 'notes', 'created_by'];

    protected $casts = ['date' => 'date', 'hours_reading' => 'float', 'km_reading' => 'float', 'worked_hours' => 'float'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(EquipmentRental::class);
    }
}

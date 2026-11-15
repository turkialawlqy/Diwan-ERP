<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** One equipment's daily preparation; its cards are attachments (kind «equipment-preparation»). */
class EquipmentPreparation extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['ready' => 'جاهزة', 'needs_attention' => 'تحتاج متابعة'];

    protected $fillable = ['company_id', 'number', 'equipment_id', 'prep_date', 'operator', 'hours_reading', 'km_reading', 'status', 'notes', 'created_by'];

    protected $casts = ['prep_date' => 'date', 'hours_reading' => 'float', 'km_reading' => 'float'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A sellable pack of prepaid visits (e.g. 10 sessions), for one service or any. */
class VisitPackage extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'name', 'bookable_service_id', 'visits', 'price', 'tax_rate', 'valid_days', 'is_active'];

    protected $casts = ['price' => 'decimal:2', 'tax_rate' => 'decimal:2', 'is_active' => 'boolean', 'visits' => 'integer', 'valid_days' => 'integer'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookableService::class, 'bookable_service_id');
    }
}

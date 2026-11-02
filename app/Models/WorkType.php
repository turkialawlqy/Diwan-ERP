<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A kind of work (maintenance, installation…) with the hourly price billed to the customer. */
class WorkType extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'name', 'hourly_rate', 'tax_rate', 'product_id', 'min_billable_minutes', 'is_active'];

    protected $casts = ['hourly_rate' => 'decimal:2', 'tax_rate' => 'decimal:2', 'is_active' => 'boolean', 'min_billable_minutes' => 'integer'];
}

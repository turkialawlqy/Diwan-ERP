<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ApprovalRule extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'document_type', 'min_amount', 'is_active'];

    protected $casts = ['min_amount' => 'decimal:2', 'is_active' => 'boolean'];
}

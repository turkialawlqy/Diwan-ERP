<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAllocationLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'run_id', 'project_id', 'basis_value', 'share_pct', 'amount', 'project_cost_id'];

    protected $casts = ['basis_value' => 'decimal:2', 'share_pct' => 'decimal:4', 'amount' => 'decimal:2'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

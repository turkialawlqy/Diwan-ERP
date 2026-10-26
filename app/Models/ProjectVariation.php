<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectVariation extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'number', 'date', 'description', 'amount', 'cost_impact', 'time_impact_days', 'status', 'approved_by', 'approved_at'];

    protected $casts = ['date' => 'date', 'approved_at' => 'datetime', 'amount' => 'decimal:2', 'cost_impact' => 'decimal:2'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function tenantParent(): ?Model
    {
        return $this->project;
    }
}

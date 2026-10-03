<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subcontract extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'supplier_id', 'number', 'scope', 'contract_value', 'retention_percent', 'advance_paid', 'advance_recovered', 'certified_to_date', 'retention_held', 'status', 'start_date', 'end_date'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SubcontractCertificate::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->project;
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgressBilling extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'invoice_id', 'number', 'sequence', 'billing_date', 'period_from', 'period_to', 'status', 'cumulative_value', 'previous_value', 'current_value', 'advance_recovery', 'retention', 'taxable_amount', 'vat_amount', 'net_payable', 'notes', 'created_by', 'approved_by'];

    protected $casts = ['billing_date' => 'date', 'period_from' => 'date', 'period_to' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProgressBillingItem::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->project;
    }
}

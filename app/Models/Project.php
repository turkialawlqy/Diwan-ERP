<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, SoftDeletes;

    protected $fillable = ['company_id', 'branch_id', 'customer_id', 'cost_center_id', 'manager_id', 'warehouse_id', 'code', 'name', 'contract_number', 'status', 'start_date', 'end_date', 'contract_value', 'advance_percent', 'retention_percent', 'advance_received', 'advance_recovered', 'retention_held', 'retention_released', 'location', 'description'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'contract_value' => 'decimal:2', 'advance_received' => 'decimal:2', 'advance_recovered' => 'decimal:2', 'retention_held' => 'decimal:2', 'retention_released' => 'decimal:2'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(ProjectBoqItem::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProjectVariation::class);
    }

    public function billings(): HasMany
    {
        return $this->hasMany(ProgressBilling::class);
    }

    public function subcontracts(): HasMany
    {
        return $this->hasMany(Subcontract::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(ProjectCost::class);
    }

    public function guarantees(): HasMany
    {
        return $this->hasMany(LetterOfGuarantee::class);
    }

    public function dailyReports(): HasMany
    {
        return $this->hasMany(ProjectDailyReport::class);
    }
}

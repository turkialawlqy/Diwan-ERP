<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A rental: the unit, the tenant, the lease contract and the subscription that bills the rent. */
class Lease extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected $fillable = ['company_id', 'branch_id', 'number', 'property_unit_id', 'customer_id', 'contract_id', 'subscription_id', 'start_date', 'end_date', 'rent_amount', 'cycle', 'security_deposit', 'residential',
        'status', 'ended_on', 'end_reason', 'created_by'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'ended_on' => 'date', 'rent_amount' => 'decimal:2', 'security_deposit' => 'decimal:2'];

    public const CYCLES = ['monthly' => 'شهري', 'quarterly' => 'ربع سنوي', 'yearly' => 'سنوي'];

    public const STATUSES = ['active' => 'ساري', 'ended' => 'منتهٍ'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PropertyUnit::class, 'property_unit_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscription::class, 'subscription_id');
    }

    /** Belongs to the branch of its parent record. */
    public function inheritedBranchId(): ?int
    {
        $id = PropertyUnit::withoutGlobalScopes()->whereKey($this->property_unit_id)->value('branch_id');

        return $id ? (int) $id : null;
    }
}

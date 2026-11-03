<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sellable subscription plan (price, billing cycle, trial). */
class ServicePlan extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected $fillable = ['company_id', 'branch_id', 'code', 'name', 'description', 'price', 'tax_rate', 'cycle', 'interval', 'trial_days', 'booking_discount_percent', 'product_id', 'is_active'];

    protected $casts = ['price' => 'decimal:2', 'tax_rate' => 'decimal:2', 'is_active' => 'boolean'];

    public const CYCLES = ['monthly' => 'شهري', 'quarterly' => 'ربع سنوي', 'yearly' => 'سنوي'];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CustomerSubscription::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Months one billing period covers. */
    public function months(): int
    {
        return RecurringInvoice::MONTHS[$this->cycle] * max(1, (int) $this->interval);
    }

    /** Price of one period per month, for MRR. */
    public function monthlyEquivalent(float $price): float
    {
        return round($price / $this->months(), 2);
    }
}

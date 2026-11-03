<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Visits a customer bought in advance; a completed booking deducts one. */
class CustomerPackage extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected $fillable = ['company_id', 'branch_id', 'number', 'customer_id', 'visit_package_id', 'bookable_service_id', 'name', 'visits_total', 'visits_used',
        'expires_at', 'status', 'invoice_id', 'created_by'];

    protected $casts = ['expires_at' => 'date', 'visits_total' => 'integer', 'visits_used' => 'integer'];

    public const STATUSES = ['active' => 'فعّالة', 'used_up' => 'مستهلكة', 'expired' => 'منتهية'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookableService::class, 'bookable_service_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PackageUsage::class)->latest('id');
    }

    public function remaining(): int
    {
        return max(0, $this->visits_total - $this->visits_used);
    }

    /** Can pay for a booking of this service on this day. */
    public function covers(?int $serviceId, $date): bool
    {
        return $this->status === 'active' && $this->remaining() > 0
            && (! $this->bookable_service_id || (int) $this->bookable_service_id === (int) $serviceId)
            && (! $this->expires_at || $this->expires_at->toDateString() >= Carbon::parse($date)->toDateString());
    }
}

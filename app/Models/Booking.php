<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's appointment on a resource for a service. */
class Booking extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected $fillable = ['company_id', 'branch_id', 'number', 'customer_id', 'bookable_service_id', 'booking_resource_id', 'starts_at', 'ends_at', 'status', 'source',
        'price', 'member_discount', 'deposit_amount', 'invoice_id', 'customer_package_id', 'portal_user_id', 'cancel_reason', 'cancelled_at', 'reminder_state', 'notes', 'created_by'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'price' => 'decimal:2', 'member_discount' => 'decimal:2',
        'deposit_amount' => 'decimal:2', 'reminder_state' => 'array'];

    public const STATUSES = ['pending' => 'بانتظار التأكيد', 'confirmed' => 'مؤكد', 'completed' => 'منجز', 'cancelled' => 'ملغى', 'no_show' => 'لم يحضر'];

    /** Statuses that occupy the resource's time. */
    public const BLOCKING = ['pending', 'confirmed', 'completed'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookableService::class, 'bookable_service_id')->withTrashed();
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'booking_resource_id')->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CustomerPackage::class, 'customer_package_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true);
    }

    /** Belongs to the branch of its parent record. */
    public function inheritedBranchId(): ?int
    {
        $id = BookingResource::withoutGlobalScopes()->whereKey($this->booking_resource_id)->value('branch_id');

        return $id ? (int) $id : null;
    }
}

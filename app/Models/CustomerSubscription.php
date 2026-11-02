<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's subscription to a service plan; its recurring invoice template does the billing. */
class CustomerSubscription extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected $fillable = ['company_id', 'branch_id', 'number', 'customer_id', 'service_plan_id', 'pending_plan_id', 'recurring_invoice_id', 'quantity', 'unit_price', 'tax_rate',
        'status', 'start_date', 'trial_ends_at', 'auto_renew', 'cancel_at_period_end', 'cancelled_at', 'cancel_reason', 'reminder_state', 'notes', 'created_by'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'tax_rate' => 'decimal:2', 'start_date' => 'date', 'trial_ends_at' => 'date',
        'auto_renew' => 'boolean', 'cancel_at_period_end' => 'boolean', 'cancelled_at' => 'date'];

    public const STATUSES = ['trial' => 'تجريبي', 'active' => 'نشط', 'paused' => 'موقوف', 'past_due' => 'متعثر', 'cancelled' => 'ملغى', 'expired' => 'منتهٍ'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'pending_plan_id');
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class, 'recurring_invoice_id');
    }

    /** Still billed (or about to be). */
    public function isLive(): bool
    {
        return in_array($this->status, ['trial', 'active', 'past_due'], true);
    }

    /** Total of one period including tax. */
    public function periodTotal(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price * (1 + (float) $this->tax_rate / 100), 2);
    }
}

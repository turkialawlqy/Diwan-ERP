<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A job sent to a customer's site: crew, checklist, time, parts, sign-off and billing. */
class WorkOrder extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected $fillable = ['company_id', 'branch_id', 'number', 'customer_id', 'ticket_id', 'booking_id', 'subscription_id', 'project_id', 'equipment_id', 'work_type_id', 'vehicle_id', 'odometer_in', 'property_unit_id', 'title', 'description',
        'site_address', 'site_phone', 'priority', 'status', 'scheduled_start', 'scheduled_end', 'started_at', 'completed_at', 'assigned_to', 'warehouse_id', 'invoice_id',
        'labour_amount', 'parts_amount', 'cost_amount', 'customer_signature_path', 'signed_by', 'signed_at', 'completion_notes', 'cancel_reason', 'reminder_state', 'created_by'];

    protected $casts = ['scheduled_start' => 'datetime', 'scheduled_end' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'signed_at' => 'datetime',
        'labour_amount' => 'decimal:2', 'parts_amount' => 'decimal:2', 'cost_amount' => 'decimal:2', 'reminder_state' => 'array'];

    public const STATUSES = ['new' => 'جديد', 'scheduled' => 'مجدول', 'in_progress' => 'قيد التنفيذ', 'on_hold' => 'معلّق', 'done' => 'منجز', 'invoiced' => 'مُفوتَر', 'cancelled' => 'ملغى'];

    public const PRIORITIES = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];

    /** Statuses in which work is still being done. */
    public const OPEN = ['new', 'scheduled', 'in_progress', 'on_hold'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function crew(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'work_order_technicians');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkOrderTask::class)->orderBy('position')->orderBy('id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class)->orderBy('started_at');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Is this employee the lead or in the crew? */
    public function hasTechnician(int $employeeId): bool
    {
        return (int) $this->assigned_to === $employeeId || $this->crew()->whereKey($employeeId)->exists();
    }
}

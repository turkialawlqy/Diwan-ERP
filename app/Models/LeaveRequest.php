<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'days', 'status', 'decision_note', 'reason', 'approved_by', 'approved_at'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'approved_at' => 'datetime', 'days' => 'decimal:2'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function tenantParent(): ?Model
    {
        return $this->employee;
    }
}

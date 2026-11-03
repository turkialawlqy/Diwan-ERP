<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One employee on an administrative request and where he is in the follow-up. */
class AdminRequestLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'admin_request_id', 'employee_id', 'department_id', 'status', 'handler_id', 'assignee_user_id', 'notify_channel', 'notify_note', 'notified_at', 'attendance_note', 'completed_at'];

    protected $casts = ['notified_at' => 'datetime', 'completed_at' => 'datetime'];

    public const STATUSES = ['new' => 'جديد', 'received' => 'تم استلامه', 'assigned' => 'أُسند لمختص', 'notified' => 'تم التبليغ', 'unreachable' => 'تعذر التبليغ', 'attended' => 'تم حضوره', 'absent' => 'لم يتم حضوره'];

    public const CHANNELS = ['call' => 'اتصال هاتفي', 'message' => 'رسالة', 'in_person' => 'مباشرة', 'email' => 'بريد إلكتروني'];

    /** No more follow-up needed. */
    public const FINAL = ['unreachable', 'attended', 'absent'];

    /** status => statuses it may move to. */
    public const FLOW = [
        'new' => ['received'],
        'received' => ['assigned', 'notified', 'unreachable'],
        'assigned' => ['notified', 'unreachable', 'received'],
        'notified' => ['attended', 'absent'],
        'unreachable' => ['received', 'assigned'],
        'attended' => [],
        'absent' => [],
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(AdminRequest::class, 'admin_request_id')->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handler_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AdminRequestEvent::class)->orderBy('id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    public function tenantParent(): ?Model
    {
        return $this->request;
    }
}

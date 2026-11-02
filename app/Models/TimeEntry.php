<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hours a technician worked; an entry without `ended_at` is a running timer. */
class TimeEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'work_order_id', 'employee_id', 'started_at', 'ended_at', 'minutes', 'billable', 'source', 'labour_cost', 'auto_closed', 'note'];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'billable' => 'boolean', 'auto_closed' => 'boolean', 'labour_cost' => 'decimal:2', 'minutes' => 'integer'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndOfServiceSettlement extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'termination_date', 'reason', 'service_years', 'last_wage', 'eos_full', 'eos_amount', 'leave_days', 'leave_encashment', 'salary_due', 'other_dues', 'loan_deduction', 'other_deductions', 'net_amount', 'status', 'bank_account_id', 'approved_by', 'notes'];

    protected $casts = ['termination_date' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->employee;
    }
}

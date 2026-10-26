<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLoan extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'date', 'amount', 'monthly_installment', 'repaid', 'first_deduction_month', 'status', 'decision_note', 'bank_account_id', 'reason', 'approved_by'];

    protected $casts = ['date' => 'date', 'first_deduction_month' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->employee;
    }
}

<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change of an employee's pay effective from a date; "previous" holds the
 * pay before it (basic_salary, housing_allowance, transport_allowance,
 * other_allowances).
 */
class EmployeeSalaryChange extends Model
{
    use BelongsToCompany;

    public const FIELDS = ['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances'];

    protected $fillable = ['company_id', 'employee_id', 'effective_date', 'previous', 'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'reason', 'created_by'];

    protected $casts = [
        'effective_date' => 'date', 'previous' => 'array',
        'basic_salary' => 'decimal:2', 'housing_allowance' => 'decimal:2', 'transport_allowance' => 'decimal:2', 'other_allowances' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenantParent(): ?Model
    {
        return $this->employee;
    }
}

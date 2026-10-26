<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'payroll_id',
        'employee_id',
        'basic_salary',
        'allowances',
        'deductions',
        'net_salary',
        'notes',
        'company_id', 'days_worked', 'housing_allowance', 'transport_allowance', 'other_allowances', 'overtime', 'bonuses', 'absence_deduction',
        'loan_deduction', 'other_deductions', 'gosi_employee', 'gosi_employer', 'gross_salary', 'cost_center_id', 'breakdown',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'allowances' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'breakdown' => 'array',
    ];

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Parent document the line inherits its company from outside a tenant context.
     */
    public function tenantParent(): ?Model
    {
        return $this->payroll;
    }
}

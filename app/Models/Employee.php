<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use Auditable, BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'national_id',
        'phone',
        'email',
        'job_title',
        'department',
        'hire_date',
        'basic_salary',
        'housing_allowance',
        'transport_allowance',
        'other_allowances',
        'total_salary',
        'bank_name',
        'iban',
        'status',
        'notes',
        'employee_number', 'department_id', 'position_id', 'manager_id', 'user_id', 'shift_id', 'cost_center_id',
        'nationality', 'gender', 'birth_date', 'marital_status', 'id_type', 'id_expiry', 'passport_number', 'passport_expiry',
        'profession', 'contract_type', 'contract_end', 'probation_end', 'gosi_number', 'gosi_registered', 'gosi_join_date',
        'annual_leave_days', 'leave_opening_balance', 'eos_opening_provision', 'termination_date', 'termination_reason', 'bank_code',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'basic_salary' => 'decimal:2',
        'housing_allowance' => 'decimal:2',
        'transport_allowance' => 'decimal:2',
        'other_allowances' => 'decimal:2',
        'total_salary' => 'decimal:2',
        'birth_date' => 'date',
        'id_expiry' => 'date',
        'passport_expiry' => 'date',
        'contract_end' => 'date',
        'probation_end' => 'date',
        'gosi_join_date' => 'date',
        'termination_date' => 'date',
        'gosi_registered' => 'boolean',
        'leave_opening_balance' => 'decimal:2',
    ];

    public function isSaudi(): bool
    {
        return strtoupper((string) $this->nationality) === 'SA';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function departmentRef(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'position_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'shift_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function salaryChanges(): HasMany
    {
        return $this->hasMany(EmployeeSalaryChange::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    public function payItems(): HasMany
    {
        return $this->hasMany(EmployeePayItem::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}

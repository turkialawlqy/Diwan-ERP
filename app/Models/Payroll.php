<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    use Auditable, BelongsToCompany, HasFactory;

    protected $fillable = [
        'bank_account_id',
        'company_id',
        'payroll_number',
        'month',
        'year',
        'total_basic',
        'total_allowances',
        'total_deductions',
        'net_total',
        'status',
        'payment_date',
        'notes',
        'created_by',
        'total_overtime', 'total_gosi_employee', 'total_gosi_employer', 'total_loans', 'approved_by', 'approved_at', 'paid_at',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'total_basic' => 'decimal:2',
        'total_allowances' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_total' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

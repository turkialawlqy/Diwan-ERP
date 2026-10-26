<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayItem extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'kind', 'name', 'amount', 'gosi_applicable', 'start_date', 'end_date'];

    protected $casts = ['gosi_applicable' => 'boolean', 'start_date' => 'date', 'end_date' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->employee;
    }
}

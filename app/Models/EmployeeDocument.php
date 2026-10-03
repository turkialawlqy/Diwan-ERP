<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'type', 'number', 'issue_date', 'expiry_date', 'file_path', 'notes'];

    protected $casts = ['issue_date' => 'date', 'expiry_date' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->employee;
    }
}

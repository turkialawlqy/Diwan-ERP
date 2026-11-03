<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientVisit extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected $fillable = ['company_id', 'branch_id', 'patient_file_id', 'booking_id', 'doctor_id', 'visit_date', 'complaint', 'diagnosis', 'treatment', 'prescription', 'next_visit_date', 'attachments', 'created_by'];

    protected $casts = ['visit_date' => 'date', 'next_visit_date' => 'date', 'attachments' => 'array'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientFile::class, 'patient_file_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'doctor_id');
    }

    /** Belongs to the branch of its parent record. */
    public function inheritedBranchId(): ?int
    {
        $id = PatientFile::withoutGlobalScopes()->whereKey($this->patient_file_id)->value('branch_id');

        return $id ? (int) $id : null;
    }
}

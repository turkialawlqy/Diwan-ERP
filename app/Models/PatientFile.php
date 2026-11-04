<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient's medical file: one per customer for the whole company (branch_id = where it was opened), so a
 * patient seen in two branches keeps one file. Each visit belongs to the branch it happened in.
 * Sensitive: its own permissions, audited, never in the portal.
 */
class PatientFile extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'branch_id', 'customer_id', 'file_number', 'birth_date', 'gender', 'blood_type', 'allergies', 'chronic_conditions', 'emergency_contact', 'notes', 'created_by'];

    protected $casts = ['birth_date' => 'date'];

    public const GENDERS = ['male' => 'ذكر', 'female' => 'أنثى'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(PatientVisit::class)->orderByDesc('visit_date')->orderByDesc('id');
    }

    /** Medical content stays out of the audit log (who changed the record is still logged). */
    public function auditSecretFields(): array
    {
        return ['allergies', 'chronic_conditions', 'blood_type', 'notes', 'emergency_contact'];
    }
}

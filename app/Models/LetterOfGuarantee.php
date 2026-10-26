<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterOfGuarantee extends Model
{
    use Auditable, BelongsToCompany;

    protected $table = 'letters_of_guarantee';

    protected $fillable = ['company_id', 'project_id', 'type', 'number', 'bank_name', 'beneficiary', 'amount', 'cash_margin', 'issue_date', 'expiry_date', 'status', 'notes'];

    protected $casts = ['issue_date' => 'date', 'expiry_date' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

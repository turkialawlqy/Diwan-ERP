<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZakatAssessment extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'fiscal_year_id', 'base', 'rate', 'amount', 'details', 'journal_entry_id', 'created_by'];

    protected $casts = ['base' => 'decimal:2', 'rate' => 'decimal:4', 'amount' => 'decimal:2', 'details' => 'array'];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}

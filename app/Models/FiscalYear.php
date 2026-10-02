<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'start_date', 'end_date', 'status', 'closing_entry_id', 'closed_at', 'closed_by'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime'];

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('start_date');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}

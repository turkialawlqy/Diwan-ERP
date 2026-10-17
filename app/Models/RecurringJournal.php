<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RecurringJournal extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'description', 'lines', 'frequency', 'next_run_date', 'end_date', 'is_active', 'last_run_at', 'created_by', 'auto_reverse', 'last_error', 'last_error_at'];

    protected $casts = ['lines' => 'array', 'next_run_date' => 'date', 'end_date' => 'date', 'is_active' => 'boolean', 'last_run_at' => 'datetime', 'auto_reverse' => 'boolean', 'last_error_at' => 'datetime'];
}

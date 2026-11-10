<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class RecurringJournal extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    protected $fillable = [
        'branch_id', 'company_id', 'name', 'description', 'lines', 'frequency', 'start_date', 'next_run_date', 'end_date', 'is_active', 'last_run_at', 'created_by', 'auto_reverse', 'last_error', 'last_error_at'];

    protected $casts = ['lines' => 'array', 'start_date' => 'date', 'next_run_date' => 'date', 'end_date' => 'date', 'is_active' => 'boolean', 'last_run_at' => 'datetime', 'auto_reverse' => 'boolean', 'last_error_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $journal) {
            $journal->start_date ??= $journal->next_run_date;
        });
    }

    /** The run after $date: the same day of the month as the first run, clamped to the month's end (the 31st comes back after February). */
    public function nextAfter(Carbon $date): Carbon
    {
        $next = $date->copy()->startOfMonth()->addMonthsNoOverflow(self::MONTHS[$this->frequency] ?? 1);

        return $next->day(min(($this->start_date ?? $date)->day, $next->daysInMonth));
    }
}

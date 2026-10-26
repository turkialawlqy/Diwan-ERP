<?php

namespace App\Models;

use App\Services\Tax\TaxProfile;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringInvoice extends Model
{
    use BelongsToCompany, BranchScoped;

    protected $fillable = ['company_id', 'customer_id', 'branch_id', 'name', 'lines', 'frequency', 'interval', 'start_date', 'end_date', 'max_count', 'generated_count',
        'next_run_date', 'currency_code', 'due_days', 'invoice_type', 'auto_send', 'send_to', 'notes', 'status', 'last_error', 'created_by'];

    protected $casts = ['lines' => 'array', 'start_date' => 'date', 'end_date' => 'date', 'next_run_date' => 'date', 'auto_send' => 'boolean'];

    public const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RecurringInvoiceRun::class);
    }

    /** Date of the issue after $date (same day of month, clamped to month end). */
    public function nextAfter(Carbon $date): Carbon
    {
        $months = self::MONTHS[$this->frequency] * max(1, (int) $this->interval);
        $day = $this->start_date->day;
        $next = $date->copy()->startOfMonth()->addMonthsNoOverflow($months);

        return $next->day(min($day, $next->daysInMonth));
    }

    /** Net total per issue in the template currency (for forecasts). */
    public function estimatedTotal(): float
    {
        return round(collect($this->lines)->sum(function ($l) {
            $net = (float) $l['quantity'] * (float) $l['unit_price'] - (float) ($l['discount'] ?? 0);

            return $net * (1 + (float) ($l['tax_rate'] ?? TaxProfile::rate()) / 100);
        }), 2);
    }

    public function isFinished(): bool
    {
        return ($this->max_count && $this->generated_count >= $this->max_count)
            || ($this->end_date && $this->next_run_date->gt($this->end_date));
    }
}

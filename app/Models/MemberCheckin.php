<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class MemberCheckin extends Model
{
    use BelongsToCompany, BranchScoped;

    public $timestamps = false;

    protected $fillable = ['company_id', 'branch_id', 'customer_id', 'subscription_id', 'customer_package_id', 'code_entered', 'method', 'allowed', 'reason', 'checked_in_at', 'checked_by'];

    protected $casts = ['allowed' => 'boolean', 'checked_in_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** One calendar day, as a range the (company, checked_in_at) index can serve; date(checked_in_at) cannot use it. */
    public function scopeOnDay(Builder $query, CarbonInterface|string $day): Builder
    {
        $start = Carbon::parse($day)->startOfDay();

        return $query->whereBetween('checked_in_at', [$start->toDateTimeString(), $start->copy()->endOfDay()->toDateTimeString()]);
    }
}

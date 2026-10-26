<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'assigned_to', 'customer_id', 'name', 'company_name', 'phone', 'email', 'source', 'status', 'expected_value', 'next_follow_up', 'lost_reason', 'notes'];

    protected $casts = ['next_follow_up' => 'date', 'expected_value' => 'decimal:2'];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }
}

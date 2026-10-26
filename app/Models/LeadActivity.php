<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'lead_id', 'user_id', 'type', 'notes', 'happened_at'];

    protected $casts = ['happened_at' => 'datetime'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->lead;
    }
}

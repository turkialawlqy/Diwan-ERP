<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}

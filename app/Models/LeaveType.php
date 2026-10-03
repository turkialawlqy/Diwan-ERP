<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name', 'days_per_year', 'pay_rule', 'deducts_balance', 'is_active'];

    protected $casts = ['deducts_balance' => 'boolean', 'is_active' => 'boolean'];
}

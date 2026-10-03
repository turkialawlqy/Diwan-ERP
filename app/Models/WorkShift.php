<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkShift extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'start_time', 'end_time', 'break_minutes', 'grace_minutes', 'work_days', 'is_default'];

    protected $casts = ['work_days' => 'array', 'is_default' => 'boolean'];
}

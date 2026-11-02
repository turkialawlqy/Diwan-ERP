<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkOrderTask extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'work_order_id', 'title', 'is_done', 'done_by', 'done_at', 'position'];

    protected $casts = ['is_done' => 'boolean', 'done_at' => 'datetime'];
}

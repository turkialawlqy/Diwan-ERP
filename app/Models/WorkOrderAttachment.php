<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkOrderAttachment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'work_order_id', 'path', 'name', 'kind', 'uploaded_by'];
}

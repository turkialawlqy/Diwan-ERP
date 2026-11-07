<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectWipEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'branch_id', 'date', 'earned', 'billed', 'amount', 'status', 'created_by'];

    protected $casts = ['date' => 'date', 'earned' => 'decimal:2', 'billed' => 'decimal:2', 'amount' => 'decimal:2'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

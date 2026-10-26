<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectDailyReport extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'date', 'weather', 'manpower', 'equipment', 'work_done', 'issues', 'created_by'];

    protected $casts = ['date' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenantParent(): ?Model
    {
        return $this->project;
    }
}

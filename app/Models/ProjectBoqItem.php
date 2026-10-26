<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectBoqItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'parent_id', 'variation_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'unit_cost', 'billed_quantity', 'is_group', 'sort'];

    protected $casts = ['quantity' => 'decimal:4', 'billed_quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'unit_cost' => 'decimal:4', 'is_group' => 'boolean'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectBoqItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProjectBoqItem::class, 'parent_id');
    }

    public function tenantParent(): ?Model
    {
        return $this->project;
    }
}

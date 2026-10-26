<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCost extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'boq_item_id', 'category', 'source', 'reference', 'date', 'description', 'product_id', 'warehouse_id', 'fixed_asset_id', 'quantity', 'unit_cost', 'amount', 'paid_from', 'supplier_id', 'bank_account_id', 'created_by'];

    protected $casts = ['date' => 'date', 'quantity' => 'decimal:4', 'amount' => 'decimal:2'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(ProjectBoqItem::class, 'boq_item_id');
    }

    public function tenantParent(): ?Model
    {
        return $this->project;
    }
}

<?php

namespace App\Traits;

use App\Models\Company;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    /**
     * Boot the BelongsToCompany trait.
     */
    protected static function bootBelongsToCompany(): void
    {
        // Global scope to isolate tenant data automatically
        static::addGlobalScope('company', function (Builder $builder) {
            $tenantManager = app(TenantManager::class);
            if ($tenantManager->hasTenant()) {
                $builder->where($builder->getModel()->getTable() . '.company_id', $tenantManager->getTenantId());
            }
        });

        // Automatically assign current company ID when creating new model instances
        static::creating(function ($model) {
            $tenantManager = app(TenantManager::class);
            if (empty($model->company_id)) {
                $model->company_id = $tenantManager->getTenantId() 
                    ?? auth()->user()?->company_id 
                    ?? \App\Models\Company::first()?->id;
            }
        });
    }

    /**
     * Relationship to the Company model.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Query scope to bypass the tenant filter when needed (e.g. Super Admin global reports).
     */
    public function scopeWithoutCompanyScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('company');
    }
}

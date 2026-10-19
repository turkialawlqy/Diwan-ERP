<?php

namespace App\Traits;

use App\Models\Company;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    use SerializesLocalDates;

    /**
     * Boot the BelongsToCompany trait.
     */
    protected static function bootBelongsToCompany(): void
    {
        // Global scope to isolate tenant data automatically
        static::addGlobalScope('company', function (Builder $builder) {
            $tenantManager = app(TenantManager::class);
            $column = $builder->getModel()->getTable().'.company_id';

            if ($tenantManager->hasTenant()) {
                $builder->where($column, $tenantManager->getTenantId());
            } elseif ($tenantManager->isEnforced()) {
                // Fail closed: a web request without a resolved tenant must never
                // see any company's rows.
                $builder->whereRaw('1 = 0');
            }
        });

        // Automatically assign current company ID when creating new model instances.
        // When a tenant is resolved, it always wins over any value already present on
        // the model (e.g. mass-assigned from request input) so a request can never
        // plant a record into another tenant by sneaking a foreign company_id through.
        static::creating(function ($model) {
            $tenantManager = app(TenantManager::class);

            if ($tenantManager->hasTenant()) {
                $model->company_id = $tenantManager->getTenantId();

                return;
            }

            if ($tenantManager->isEnforced()) {
                throw new \RuntimeException(
                    'Cannot create '.class_basename($model).' without a resolved tenant.'
                );
            }

            if (empty($model->company_id) && method_exists($model, 'tenantParent')) {
                $model->company_id = $model->tenantParent()?->company_id;
            }

            if (empty($model->company_id)) {
                $companyId = auth()->user()?->company_id;

                if (! $companyId) {
                    throw new \RuntimeException(
                        'Cannot create '.class_basename($model).' without a resolved tenant. '
                        .'Ensure the request has a valid company context.'
                    );
                }

                $model->company_id = $companyId;
            }
        });

        // Prevent an existing record from being silently reassigned to another
        // tenant (e.g. via a mass update that happens to include company_id).
        static::updating(function ($model) {
            $tenantManager = app(TenantManager::class);
            if ($tenantManager->hasTenant()
                && $model->isDirty('company_id')
                && (int) $model->company_id !== (int) $tenantManager->getTenantId()
            ) {
                $model->company_id = $tenantManager->getTenantId();
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

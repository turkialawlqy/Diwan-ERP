<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Branch data scope: a user tied to a branch only sees documents of that
 * branch (and documents without a branch), unless they hold
 * "admin.data_scope.manage". Jobs and console commands are not restricted.
 */
trait BranchScoped
{
    public static function bootBranchScoped(): void
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            $branchId = static::restrictedBranchId();
            if ($branchId === null) {
                return;
            }

            $column = $builder->getModel()->qualifyColumn('branch_id');
            $builder->where(fn ($q) => $q->where($column, $branchId)->orWhereNull($column));
        });

        static::creating(function ($model) {
            if (empty($model->branch_id) && ($branchId = static::restrictedBranchId()) !== null) {
                $model->branch_id = $branchId;
            }
        });
    }

    protected static function restrictedBranchId(): ?int
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        $user = auth()->user();
        if (! $user || ! $user->branch_id || $user->isCompanyOwner() || $user->isSuperAdmin()) {
            return null;
        }

        // Spatie caches the user's permissions in memory, so this stays cheap.
        return $user->can('admin.data_scope.manage') ? null : (int) $user->branch_id;
    }
}

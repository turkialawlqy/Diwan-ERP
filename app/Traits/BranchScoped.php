<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Branch data scope: a user restricted to some branches only sees documents
 * of those branches (and documents without a branch), and cannot file one in
 * another branch. The owner and holders of "admin.data_scope.manage" see all.
 * Jobs and console commands are not restricted.
 */
trait BranchScoped
{
    public static function bootBranchScoped(): void
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            $branchIds = static::restrictedBranchIds();
            if ($branchIds === null) {
                return;
            }

            $column = $builder->getModel()->qualifyColumn('branch_id');
            $builder->where(fn ($q) => $q->whereIn($column, $branchIds)->orWhereNull($column));
        });

        static::creating(function ($model) {
            $user = static::scopedUser();
            if (! $user) {
                return;
            }
            if (empty($model->branch_id)) {
                $model->branch_id = $user->defaultBranchId();
            }
            if (! $user->canUseBranch($model->branch_id ? (int) $model->branch_id : null)) {
                throw ValidationException::withMessages(['branch_id' => 'لا تملك صلاحية العمل على هذا الفرع.']);
            }
        });
    }

    /**
     * The signed-in user whose branches apply, or null outside a user request.
     */
    protected static function scopedUser(): ?User
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }
        $user = auth()->user();

        return $user instanceof User && $user->company_id ? $user : null;
    }

    /**
     * @return array<int>|null null: no restriction
     */
    protected static function restrictedBranchIds(): ?array
    {
        return static::scopedUser()?->allowedBranchIds();
    }
}

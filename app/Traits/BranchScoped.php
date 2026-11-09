<?php

namespace App\Traits;

use App\Models\User;
use App\Services\ActiveBranch;
use App\Support\BranchScopeSwitch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Branch data scope: lists show the branch chosen in the top bar (or every
 * branch the user may use while "all branches" is shown), plus documents
 * without a branch. A new record belongs to the chosen branch, and no record
 * can be filed in or moved to a branch the user may not work in. Jobs and
 * console commands are not restricted.
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
            // A document hanging on another one (a payment on its invoice) belongs to that branch.
            if (empty($model->branch_id) && method_exists($model, 'inheritedBranchId')) {
                $model->branch_id = $model->inheritedBranchId();
            }

            $user = static::scopedUser();
            if (! $user) {
                return;
            }
            if (empty($model->branch_id)) {
                $model->branch_id = app(ActiveBranch::class)->forWrite();
            }
            static::assertUsableBranch($user, $model);
        });

        static::updating(function ($model) {
            $user = $model->isDirty('branch_id') ? static::scopedUser() : null;
            if ($user) {
                static::assertUsableBranch($user, $model);
            }
        });
    }

    /**
     * @throws ValidationException when the user may not work in the model's branch
     */
    protected static function assertUsableBranch(User $user, $model): void
    {
        if (! $user->canUseBranch($model->branch_id ? (int) $model->branch_id : null)) {
            throw ValidationException::withMessages(['branch_id' => 'لا تملك صلاحية العمل على هذا الفرع.']);
        }
    }

    /**
     * The signed-in user whose branch applies, or null outside a user request.
     */
    protected static function scopedUser(): ?User
    {
        if (BranchScopeSwitch::isOff()) {
            return null;
        }
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }
        // The customer portal is the customer's view: a staff member signed in on the same browser must not narrow it to his branch.
        if (request()->routeIs('customer-portal.*')) {
            return null;
        }
        $user = auth()->user();

        // A platform admin counts too: ActiveBranch leaves their lists unrestricted until they choose a branch, and what they save needs one.
        return $user instanceof User && ($user->company_id || $user->isSuperAdmin()) ? $user : null;
    }

    /**
     * @return array<int>|null null: no restriction
     */
    protected static function restrictedBranchIds(): ?array
    {
        return static::scopedUser() ? app(ActiveBranch::class)->scopeIds() : null;
    }
}

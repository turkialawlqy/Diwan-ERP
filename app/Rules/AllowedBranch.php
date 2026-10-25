<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The branch is one the signed-in user may work in (see User::allowedBranchIds).
 */
class AllowedBranch implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = auth()->user();
        if ($value !== null && $value !== '' && $user instanceof User && ! $user->canUseBranch((int) $value)) {
            $fail('لا تملك صلاحية العمل على هذا الفرع.');
        }
    }
}

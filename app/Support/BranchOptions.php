<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use App\Services\ActiveBranch;
use App\Services\Tax\TaxProfile;

/**
 * The branch a new document starts in: the one chosen in the top bar, with
 * its tax profile so the form follows its rate and currency. Forms no longer
 * offer a branch choice; the branch is chosen once, in the top bar.
 */
class BranchOptions
{
    /**
     * @return array{branches: array<int, array<string, mixed>>, defaultBranchId: ?int}
     */
    public static function forUser(?User $user = null): array
    {
        // Creating needs a specific branch: with "all branches" shown, ask for one.
        $id = app(ActiveBranch::class)->forWrite();

        // Not from ActiveBranch::branches(): a platform admin inside a company has no list of their own.
        $branch = $id ? Branch::find($id) : null;
        if (! $branch) {
            return ['branches' => [], 'defaultBranchId' => $id];
        }

        return [
            'branches' => [[
                'id' => $branch->id,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'country_code' => $branch->country_code,
                'tax' => TaxProfile::forBranch($branch)->toArray(),
            ]],
            'defaultBranchId' => $branch->id,
        ];
    }
}

<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use App\Services\Tax\TaxProfile;

/**
 * The branches a document form offers the signed-in user (only those they
 * may work in), each with its tax profile so the form can follow the chosen
 * branch's rate and currency, plus the branch to pre-select.
 */
class BranchOptions
{
    /**
     * @return array{branches: array<int, array<string, mixed>>, defaultBranchId: ?int}
     */
    public static function forUser(?User $user = null): array
    {
        $user ??= auth()->user();
        $allowed = $user?->allowedBranchIds();

        $branches = Branch::where('is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderByDesc('is_main')->orderBy('id')
            ->get()
            ->map(fn (Branch $b) => [
                'id' => $b->id,
                'name_ar' => $b->name_ar,
                'name_en' => $b->name_en,
                'country_code' => $b->country_code,
                'tax' => TaxProfile::forBranch($b)->toArray(),
            ])->values();

        $default = $user?->defaultBranchId();
        if (! $branches->contains('id', $default)) {
            $default = $branches->first()['id'] ?? null;
        }

        return ['branches' => $branches->all(), 'defaultBranchId' => $default];
    }
}

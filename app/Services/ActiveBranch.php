<?php

namespace App\Services;

use App\Exceptions\BranchRequiredException;
use App\Models\Branch;
use App\Models\User;
use App\Services\Tax\TaxProfile;
use Illuminate\Support\Collection;

/**
 * The branch the signed-in user is working in, chosen in the top bar and kept
 * in the session. Everything that reads or creates branch data follows it:
 * lists, tax rules and currency, and the branch of every new document.
 *
 * "All branches" (session value 'all') is a viewing mode for users who may
 * work in more than one branch: lists and reports then cover every branch they
 * may use, and nothing can be created until a specific branch is chosen.
 *
 * Outside a web session (console, queue, API tokens) there is no choice to
 * follow: the user's default branch applies, and jobs are not restricted.
 */
class ActiveBranch
{
    public const SESSION_KEY = 'active_branch_id';

    public const ALL = 'all';

    /** @var array<string, Collection> memo of the user's branches, per user and company */
    protected array $memo = [];

    public function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->company_id ? $user : null;
    }

    /**
     * Active branches the user may work in, main branch first.
     *
     * @return Collection<int, Branch>
     */
    public function branches(): Collection
    {
        $user = $this->user();
        if (! $user) {
            return collect();
        }

        // Permissions can change within a request (all-branches access): part of the key.
        $key = $user->id.'|'.$user->company_id.'|'.(int) $user->hasAllBranches();
        if (! isset($this->memo[$key])) {
            $allowed = $user->allowedBranchIds();
            $this->memo[$key] = Branch::where('is_active', true)
                ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
                ->orderByDesc('is_main')->orderBy('id')->get();
        }

        return $this->memo[$key];
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /** More than one branch to choose from (the top bar shows a select). */
    public function hasChoice(): bool
    {
        return $this->branches()->count() > 1;
    }

    /** May the user look at all their branches together? */
    public function canViewAll(): bool
    {
        return $this->hasChoice();
    }

    protected function stored(): mixed
    {
        return request()->hasSession() ? request()->session()->get(self::SESSION_KEY) : null;
    }

    public function isAll(): bool
    {
        return $this->stored() === self::ALL && $this->canViewAll();
    }

    /**
     * The chosen branch, or null when viewing all branches (or no branches exist).
     */
    public function id(): ?int
    {
        if ($this->isAll()) {
            return null;
        }

        return $this->contextId();
    }

    /**
     * The branch whose rules apply (tax, currency, country features): the chosen
     * one, the user's default while viewing all branches, else the main branch.
     */
    public function contextId(): ?int
    {
        $branches = $this->branches();
        $stored = $this->stored();
        if (is_numeric($stored) && $branches->contains('id', (int) $stored)) {
            return (int) $stored;
        }

        $default = $this->user()?->defaultBranchId();
        if ($default && $branches->contains('id', $default)) {
            return $default;
        }

        return $branches->first()?->id;
    }

    /**
     * Branch ids lists and reports cover: the chosen branch, or every branch
     * the user may use while viewing all. Null: no restriction.
     *
     * @return array<int>|null
     */
    public function scopeIds(): ?array
    {
        $user = $this->user();
        if (! $user) {
            return null;
        }

        if ($this->isAll()) {
            return $user->allowedBranchIds();
        }

        $id = $this->id();

        return $id === null ? $user->allowedBranchIds() : [$id];
    }

    /**
     * scopeIds() as part of a cache key, for figures cached per scope: 'all'
     * when unrestricted, else the sorted ids (hashed when long, as database
     * cache keys are limited to 255 characters).
     */
    public function scopeKey(): string
    {
        $ids = $this->scopeIds();
        if ($ids === null) {
            return self::ALL;
        }
        sort($ids);
        $key = implode(',', $ids);

        return strlen($key) > 64 ? md5($key) : $key;
    }

    /**
     * The branch a new record belongs to. Refuses while "all branches" is shown.
     *
     * @throws BranchRequiredException
     */
    public function forWrite(): ?int
    {
        if (! $this->user()) {
            return null;
        }
        if ($this->isAll()) {
            throw new BranchRequiredException;
        }

        return $this->id();
    }

    /**
     * May the user work in $value (a branch id or 'all')?
     */
    public function allows(int|string $value): bool
    {
        return $value === self::ALL
            ? $this->canViewAll()
            : $this->branches()->contains('id', (int) $value);
    }

    /**
     * Switch the working branch. $value is a branch id or 'all'.
     */
    public function switchTo(int|string $value): bool
    {
        if (! $this->allows($value)) {
            return false;
        }

        request()->session()->put(self::SESSION_KEY, $value === self::ALL ? self::ALL : (int) $value);

        return true;
    }

    /**
     * Header data for the branch switcher.
     *
     * @return array{items: array<int, array<string, mixed>>, active: int|string|null, can_all: bool}
     */
    public function switcher(): array
    {
        $items = $this->branches()->map(function (Branch $b) {
            $profile = TaxProfile::forBranch($b);

            return [
                'id' => $b->id,
                'name' => $b->name_ar ?: $b->name_en,
                'country' => $profile->countryCode,
                'country_name' => $profile->country['name'],
                'currency' => $profile->currency(),
            ];
        })->values()->all();

        return [
            'items' => $items,
            'active' => $this->isAll() ? self::ALL : $this->contextId(),
            'can_all' => $this->canViewAll(),
        ];
    }
}

<?php

namespace App\Models;

use App\Services\Accounting\AccountMap;
use App\Services\TenantManager;
use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class BankAccount extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'company_id',
        'account_id',
        'name',
        'type',
        'bank_name',
        'account_number',
        'iban',
        'currency',
        'opening_balance',
        'current_balance',
        'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * The box's balance as the books show it: its ledger account. The stored
     * current_balance is only moved by some screens (invoices and vouchers post
     * to the ledger alone), so it is kept as a fallback for unlinked boxes.
     */
    public function ledgerBalance(): float
    {
        if (! $this->account_id) {
            return (float) $this->current_balance;
        }

        return (float) Account::withoutGlobalScopes()->whereKey($this->account_id)->value('balance');
    }

    /**
     * Of these accounts, the ledgers of boxes (archived ones too: their ledger is still that branch's history),
     * as account id => the branches of its boxes (null: a box shared by every branch). An account the system
     * posts to for every branch (an AccountMap role: default cash, default bank, input VAT...) is left out: a box
     * sitting on one shares it with every branch, so no branch can be kept off it.
     *
     * @param  iterable<int|string|null>  $accountIds
     * @return Collection<int, Collection<int, int|null>>
     */
    public static function ledgerBranches(iterable $accountIds, ?int $companyId = null): Collection
    {
        $companyId ??= app(TenantManager::class)->getTenantId();
        $ids = collect($accountIds)->map(fn ($id) => (int) $id)->filter()->unique()
            ->diff($companyId ? array_filter(app(AccountMap::class)->all($companyId)) : [])->values();
        if (! $companyId || $ids->isEmpty()) {
            return collect();
        }

        return static::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('account_id', $ids)->get(['account_id', 'branch_id'])
            ->groupBy(fn (self $box) => (int) $box->account_id)
            ->map(fn ($boxes) => $boxes->map(fn (self $box) => $box->branch_id === null ? null : (int) $box->branch_id)->unique()->values());
    }

    /**
     * Of these accounts, the ledgers of boxes none of which is in a branch $user may work in (or shared by all):
     * a line on one moves another branch's box from outside it. Ledgers of the user's own boxes, of a shared box,
     * and role accounts (ledgerBranches) are not among them. Without a signed-in company user nothing is hidden.
     *
     * @param  iterable<int|string|null>  $accountIds
     * @return array<int, int>
     */
    public static function hiddenLedgers(iterable $accountIds, ?User $user = null): array
    {
        $user ??= auth()->user();
        $allowed = $user instanceof User ? $user->allowedBranchIds() : null;
        if ($allowed === null) {
            return [];
        }

        return static::ledgerBranches($accountIds)
            ->reject(fn ($branches) => $branches->contains(fn ($branch) => $branch === null || in_array($branch, $allowed, true)))
            ->keys()->all();
    }

    /**
     * Of these accounts, the ledgers of boxes none of which is of $branchId (or shared by all): an entry of that
     * branch moving one moves another branch's box (PostingService refuses it unless told the move is meant).
     *
     * @param  iterable<int|string|null>  $accountIds
     * @return array<int, int>
     */
    public static function foreignLedgers(?int $branchId, iterable $accountIds, ?int $companyId = null): array
    {
        if (! $branchId) {
            return [];
        }

        return static::ledgerBranches($accountIds, $companyId)
            ->reject(fn ($branches) => $branches->contains(fn ($branch) => $branch === null || $branch === $branchId))
            ->keys()->all();
    }

    /**
     * Of these accounts, those that are the ledger of a box (any branch, shared or archived, role accounts
     * included); with $branchesOnly, of a box that has a branch.
     *
     * @param  iterable<int|string|null>  $accountIds
     * @return array<int, int>
     */
    public static function boxLedgers(iterable $accountIds, bool $branchesOnly = false, ?int $companyId = null): array
    {
        $companyId ??= app(TenantManager::class)->getTenantId();
        $ids = collect($accountIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if (! $companyId || $ids->isEmpty()) {
            return [];
        }

        return static::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('account_id', $ids)
            ->when($branchesOnly, fn ($q) => $q->whereNotNull('branch_id'))
            ->distinct()->pluck('account_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Replace current_balance with the ledger balance for display. */
    public static function withLedgerBalances(iterable $boxes): iterable
    {
        $balances = Account::withoutGlobalScopes()->whereIn('id', collect($boxes)->pluck('account_id')->filter()->unique())->pluck('balance', 'id');
        foreach ($boxes as $box) {
            if ($box->account_id && $balances->has($box->account_id)) {
                $box->current_balance = (float) $balances[$box->account_id];
            }
        }

        return $boxes;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function revenues(): HasMany
    {
        return $this->hasMany(Revenue::class);
    }
}

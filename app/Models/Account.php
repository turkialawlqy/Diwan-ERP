<?php

namespace App\Models;

use App\Services\Accounting\AccountMap;
use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Account extends Model
{
    use Auditable, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name_ar',
        'name_en',
        'type',
        'nature',
        'is_parent',
        'balance',
        'is_active',
        'is_system',
        'cash_flow_category',
        'requires_cost_center',
    ];

    protected $casts = [
        'is_parent' => 'boolean',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'requires_cost_center' => 'boolean',
        'balance' => 'decimal:2',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /**
     * The system posts to this account by itself: the ledger of a box (any branch, shared or archived), or an account
     * mapped to a role (AccountMap). It never becomes a group account, whose postings would all be refused.
     */
    public function isPostedBySystem(): bool
    {
        return BankAccount::boxLedgers([$this->id], false, $this->company_id) !== []
            || in_array((int) $this->id, array_map('intval', array_filter(app(AccountMap::class)->all($this->company_id))), true);
    }

    /**
     * The settings that post to this account by themselves, besides the roles and box ledgers of isPostedBySystem: asset
     * categories (their asset, accumulated-depreciation or expense account), recurring entries of every branch (a line on
     * it; with $activeOnly, only those that still run) and customer groups (their customers' receivables). The chart keeps
     * the account as they need it (AccountController::settingsProblem, the chart import); BankAccount::configuredAccounts
     * keeps new boxes off the same accounts: a new kind of setting goes in both.
     *
     * @return array{categories: Collection<int, AssetCategory>, recurring: Collection<int, RecurringJournal>, groups: Collection<int, CustomerGroup>}
     */
    public function postingSettings(bool $activeOnly = false): array
    {
        $id = (int) $this->id;

        return [
            'categories' => AssetCategory::withoutGlobalScopes()->where('company_id', $this->company_id)
                ->where(fn ($q) => $q->where('asset_account_id', $id)->orWhere('accumulated_account_id', $id)->orWhere('expense_account_id', $id))
                ->orderBy('id')->get(['id', 'name_ar']),
            'recurring' => RecurringJournal::withoutGlobalScopes()->where('company_id', $this->company_id)
                ->when($activeOnly, fn ($q) => $q->where('is_active', true))
                ->orderBy('id')->get(['id', 'branch_id', 'name', 'lines'])
                ->filter(fn (RecurringJournal $entry) => in_array($id, array_map('intval', array_column((array) $entry->lines, 'account_id')), true))
                ->values(),
            'groups' => CustomerGroup::withoutGlobalScopes()->where('company_id', $this->company_id)->where('account_id', $id)->orderBy('id')->get(['id', 'name']),
        ];
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }

    /**
     * Debit-nature accounts (assets, expenses) grow with debits.
     */
    public function isDebitNature(): bool
    {
        return $this->nature === 'debit';
    }

    /**
     * Signed balance from debit / credit totals according to the account nature.
     */
    public function signedBalance(float $debit, float $credit): float
    {
        return round($this->isDebitNature() ? $debit - $credit : $credit - $debit, 2);
    }
}

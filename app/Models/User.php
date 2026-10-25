<?php

namespace App\Models;

use App\Traits\SerializesLocalDates;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SerializesLocalDates;

    /**
     * The attributes that are mass assignable. company_id and is_super_admin are
     * deliberately excluded so no request can move a user between tenants or
     * escalate to platform admin; set them explicitly with forceFill().
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'branch_id',
        'status',
        'google_id',
        'last_login_at',
        'commission_rate',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_step',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Branches the user may work in. users.branch_id is the default among them.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /** @var array<int>|false|null memoised branch rows of a restricted user (false: not loaded yet) */
    protected array|false|null $allowedBranchIdsCache = false;

    /**
     * Works in every branch: the company owner, the platform admin and holders
     * of "admin.data_scope.manage".
     */
    public function hasAllBranches(): bool
    {
        return $this->isCompanyOwner() || $this->isSuperAdmin() || $this->can('admin.data_scope.manage');
    }

    /**
     * Branch ids the user may create in and see, or null for all branches.
     *
     * @return array<int>|null
     */
    public function allowedBranchIds(): ?array
    {
        if ($this->hasAllBranches()) {
            return null;
        }
        if ($this->allowedBranchIdsCache === false) {
            $ids = $this->branches()->withoutGlobalScopes()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
            // No branches assigned yet: their default branch, else the company's main branch
            // (a company without branches has nothing to restrict).
            if ($ids === []) {
                $fallback = $this->branch_id ?: Branch::withoutGlobalScopes()->where('company_id', $this->company_id)->orderByDesc('is_main')->orderBy('id')->value('id');
                $ids = $fallback ? [(int) $fallback] : null;
            }
            $this->allowedBranchIdsCache = $ids;
        }

        return $this->allowedBranchIdsCache;
    }

    public function canUseBranch(?int $branchId): bool
    {
        $allowed = $this->allowedBranchIds();

        return $allowed === null || ($branchId !== null && in_array($branchId, $allowed, true));
    }

    /**
     * Branch pre-selected on new documents: the user's default when allowed,
     * else their first allowed branch (null for an all-branches user without a default).
     */
    public function defaultBranchId(): ?int
    {
        $allowed = $this->allowedBranchIds();
        if ($this->branch_id && $this->canUseBranch((int) $this->branch_id)) {
            return (int) $this->branch_id;
        }

        // All branches and no default: null, so callers fall back to the main branch.
        return $allowed[0] ?? null;
    }

    public function forgetAllowedBranches(): void
    {
        $this->allowedBranchIdsCache = false;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && ! empty($this->two_factor_secret);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->isCompanyOwner() || $this->isSuperAdmin();
    }

    /**
     * Holds the company's owner role (full, non-revocable access).
     */
    public function isCompanyOwner(): bool
    {
        return $this->company_id !== null
            && $this->hasRole(config('erp_permissions.owner_role', 'admin'));
    }

    /**
     * End every way back in: API tokens, stored sessions and the remember-me
     * cookie. Sessions on other drivers are cut by the status check and
     * AuthenticateSession (password hash) on their next request.
     */
    public function revokeAccess(): void
    {
        $this->tokens()->delete();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();
        }
        $this->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    /**
     * Has the user recorded business activity? Such users are deactivated,
     * never deleted, so documents keep who issued them.
     */
    public function hasActivity(): bool
    {
        foreach ([['pos_orders', 'user_id'], ['pos_sessions', 'user_id'], ['invoices', 'user_id'], ['journal_entries', 'created_by']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}

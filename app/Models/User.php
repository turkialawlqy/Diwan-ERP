<?php

namespace App\Models;

use App\Traits\SerializesLocalDates;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

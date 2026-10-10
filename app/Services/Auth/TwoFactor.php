<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two-factor authentication: enrolment, recovery codes, login verification
 * and the per-company policy that makes it mandatory for chosen roles.
 * Recovery codes are kept only as SHA-256 hashes (inside an encrypted column)
 * and shown in plain text once, when generated.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public function __construct(protected Totp $totp) {}

    /** Starts enrolment: stores an unconfirmed secret and returns its setup data. */
    public function begin(User $user): array
    {
        $secret = $this->totp->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();

        return $this->setup($user);
    }

    public function setup(User $user): array
    {
        $issuer = trim(config('app.name').($user->company ? ' — '.$user->company->name : ''));

        return [
            'secret' => trim(chunk_split($user->two_factor_secret, 4, ' ')),
            'uri' => $this->totp->provisioningUri($user->two_factor_secret, $user->email, $issuer),
        ];
    }

    /** Confirms enrolment with a first code; returns the plain recovery codes. */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || ! ($step = $this->totp->verify($user->two_factor_secret, $code))) {
            return null;
        }
        $codes = $this->freshRecoveryCodes($user);
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();
        AuditLog::record('two_factor_enabled', $user, [], [], $user->company_id, $user->id);

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        AuditLog::record('two_factor_disabled', $user, [], [], $user->company_id, $user->id);
    }

    public function freshRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODES))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    /** Verifies a login code or a single-use recovery code. */
    public function verifyLogin(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if ($code) {
            $step = $this->totp->verify((string) $user->two_factor_secret, $code, $user->two_factor_last_step);
            if ($step) {
                $user->forceFill(['two_factor_last_step' => $step])->saveQuietly();

                return true;
            }
        }

        if ($recoveryCode) {
            $hash = hash('sha256', Str::lower(trim($recoveryCode)));
            $remaining = (array) $user->two_factor_recovery_codes;
            $index = array_search($hash, $remaining, true);
            if ($index !== false) {
                unset($remaining[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->saveQuietly();
                AuditLog::record('two_factor_recovery_used', $user, [], ['remaining' => count($remaining)], $user->company_id, $user->id);

                return true;
            }
        }

        AuditLog::record('two_factor_failed', $user, [], [], $user->company_id, $user->id);

        return false;
    }

    /** Platform admins always; company users when their role is in the company policy. */
    public function required(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return (bool) config('security.two_factor_super_admin', true);
        }
        $company = $user->company;
        $roles = $company ? $this->requiredRoles($company) : [];
        if (! $roles) {
            return false;
        }
        if (in_array('*', $roles, true)) {
            return true;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->id);
        try {
            $user->unsetRelation('roles');

            return $user->hasAnyRole($roles);
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    public function requiredRoles(Company $company): array
    {
        $value = $company->setting('two_factor_required_roles', []);

        return array_values(array_filter(is_array($value) ? $value : (array) json_decode((string) $value, true)));
    }

    public function setRequiredRoles(Company $company, array $roles): void
    {
        $company->setSetting('two_factor_required_roles', array_values($roles), 'security', 'json');
    }
}

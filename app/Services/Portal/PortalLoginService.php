<?php

namespace App\Services\Portal;

use App\Models\PortalLoginCode;
use App\Models\PortalUser;
use App\Notifications\PortalLoginCode as PortalLoginCodeMail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Passwordless sign-in for portal users: a 6-digit code by e-mail, stored only as a keyed hash,
 * valid for 10 minutes, one use, at most 5 wrong tries, rate limited per e-mail and IP, and at
 * most 15 failed tries per e-mail a day. Neither sending nor the limits reveal whether the e-mail
 * belongs to a portal user.
 */
class PortalLoginService
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /** Codes that may be requested per e-mail within 10 minutes, and per IP within an hour. */
    public const SEND_PER_EMAIL = 3;

    public const SEND_PER_IP = 20;

    /**
     * Failed tries an e-mail may make in a day (three codes' worth) before codes stop for it until the next
     * day. Without it each new code brings five new tries: about 2,160 guesses a day on one account.
     */
    public const FAILED_TRIES_PER_DAY = 15;

    /**
     * @return bool false when rate limited (the caller shows a «try later» message; it says nothing about the e-mail)
     */
    public function send(int $companyId, string $email, ?string $ip): bool
    {
        $email = Str::lower(trim($email));
        $emailKey = "portal-code:{$companyId}:{$email}";
        $ipKey = 'portal-code-ip:'.($ip ?? 'unknown');

        // Counted before the lookup, so unknown and known e-mails behave exactly the same.
        if (RateLimiter::tooManyAttempts($emailKey, self::SEND_PER_EMAIL) || RateLimiter::tooManyAttempts($ipKey, self::SEND_PER_IP)
            || RateLimiter::tooManyAttempts($this->failedKey($companyId, $email), self::FAILED_TRIES_PER_DAY)) {
            return false;
        }
        RateLimiter::hit($emailKey, self::TTL_MINUTES * 60);
        RateLimiter::hit($ipKey, 3600);

        $user = PortalUser::where('company_id', $companyId)->where('email', $email)->where('status', 'active')->first();
        if (! $user) {
            return true;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->codes()->whereNull('used_at')->delete(); // a new code cancels the older ones
        $user->codes()->create(['code_hash' => $this->hash($user, $code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES), 'ip' => $ip, 'created_at' => now()]);

        try {
            $user->notify(new PortalLoginCodeMail($code, self::TTL_MINUTES));
        } catch (Throwable $e) {
            report($e); // the visitor sees the same answer either way
        }

        return true;
    }

    /** Check a code; on success the code is used up and the user is returned. */
    public function verify(int $companyId, string $email, string $code): ?PortalUser
    {
        $failed = $this->failedKey($companyId, Str::lower(trim($email)));
        if (RateLimiter::tooManyAttempts($failed, self::FAILED_TRIES_PER_DAY)) {
            return null; // too many failed tries today: even the right code waits for tomorrow
        }
        $user = $this->check($companyId, $email, $code);
        if (! $user) {
            RateLimiter::hit($failed, 86400); // known e-mail or not, a failed try counts the same

            return null;
        }
        RateLimiter::clear($failed);

        return $user;
    }

    protected function check(int $companyId, string $email, string $code): ?PortalUser
    {
        $user = PortalUser::where('company_id', $companyId)->where('email', Str::lower(trim($email)))->where('status', 'active')->first();
        if (! $user) {
            return null;
        }

        /** @var PortalLoginCode|null $row */
        $row = $user->codes()->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $row) {
            return null;
        }

        // Atomic: a try counts only if it took one of the 5 slots, so parallel guesses cannot pass the limit.
        if (! PortalLoginCode::whereKey($row->id)->whereNull('used_at')->where('attempts', '<', self::MAX_ATTEMPTS)->increment('attempts')) {
            return null;
        }
        if (! hash_equals($row->code_hash, $this->hash($user, preg_replace('/\D/', '', $code)))) {
            return null;
        }
        // Single use, also under two simultaneous correct submissions: only the one that marks it wins.
        if (! PortalLoginCode::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()])) {
            return null;
        }
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    protected function failedKey(int $companyId, string $email): string
    {
        return "portal-code-failed:{$companyId}:{$email}";
    }

    protected function hash(PortalUser $user, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.$code, (string) config('app.key'));
    }
}

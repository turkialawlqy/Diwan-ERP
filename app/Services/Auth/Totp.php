<?php

namespace App\Services\Auth;

/**
 * Time-based one-time passwords (RFC 6238, HMAC-SHA1, 30 s, 6 digits) — the
 * codes shown by Google Authenticator, Microsoft Authenticator, Authy, etc.
 */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function code(string $secret, ?int $timestamp = null): string
    {
        return $this->codeAt($secret, intdiv($timestamp ?? time(), self::PERIOD));
    }

    /**
     * Checks a code within ±$window steps and returns the matching time step,
     * or null. Steps at or before $lastUsedStep are rejected so a code that was
     * already used (or an older one) can never be replayed.
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep = null, int $window = 1, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', strtr($code, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $current = intdiv($timestamp ?? time(), self::PERIOD);
        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals($this->codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=".rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $step), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[\s=]+/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}

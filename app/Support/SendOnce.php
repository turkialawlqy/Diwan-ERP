<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends something once per key, for jobs that may run again (by hand, or after a failure). The key
 * is taken before sending, so two runs at the same moment cannot both send it, and given back if
 * sending fails, so a later run tries again.
 */
final class SendOnce
{
    /**
     * @param  DateTimeInterface  $until  how long the key is remembered
     * @return bool true if this call sent it, false if it had already gone out
     */
    public static function attempt(string $key, DateTimeInterface $until, callable $send): bool
    {
        if (! Cache::add($key, true, $until)) {
            return false;
        }

        try {
            $send();
        } catch (Throwable $e) {
            Cache::forget($key);

            throw $e;
        }

        return true;
    }
}

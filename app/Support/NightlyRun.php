<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * One record's step in a nightly / periodic run (subscriptions, contracts, bookings, work orders, SLA).
 * A record that fails — bad data, a mail server that does not answer — is reported with its id and
 * counted under «failed», and the run goes on with the next record. Without it, one bad record stops
 * the rest of the company's records every night until someone fixes it.
 *
 *     Contract::where(...)->lazyById()->each(NightlyRun::step(function (Contract $c) use (&$done) { ... }, $done));
 */
final class NightlyRun
{
    public static function step(callable $step, array &$done): Closure
    {
        return function (...$args) use ($step, &$done) {
            try {
                $step(...$args);
            } catch (Throwable $e) {
                $record = $args[0] ?? null;
                report($record instanceof Model ? new RuntimeException(class_basename($record).' #'.$record->getKey().': '.$e->getMessage(), 0, $e) : $e);
                $done['failed'] = ($done['failed'] ?? 0) + 1;
            }
        };
    }
}

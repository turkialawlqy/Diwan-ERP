<?php

namespace App\Support;

/**
 * Lets trusted service code (sector-pack seeding) read and write branch records without the signed-in
 * user's branch scope, e.g. to create a catalogue shared by every branch (branch_id NULL).
 */
class BranchScopeSwitch
{
    protected static int $depth = 0;

    public static function isOff(): bool
    {
        return self::$depth > 0;
    }

    /** @template T @param callable():T $callback @return T */
    public static function off(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}

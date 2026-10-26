<?php

namespace App\Traits;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Dates reach the browser in the application's time zone with its offset
 * (2026-10-19T00:00:00+03:00), not converted to UTC. A date-only column
 * at local midnight would otherwise read as the previous day
 * (2026-10-18T21:00:00Z) wherever the first ten characters are shown or
 * put back into a form.
 */
trait SerializesLocalDates
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)->setTimezone(config('app.timezone'))->toIso8601String();
    }
}

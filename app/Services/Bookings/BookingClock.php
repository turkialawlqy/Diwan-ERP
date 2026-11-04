<?php

namespace App\Services\Bookings;

use App\Services\Tax\TaxProfile;
use Illuminate\Support\Carbon;

/**
 * «Now» as the wall clock of a branch. Booking times are wall-clock times of the place the booking is
 * for (working hours are «09:00» there), stored in the company's own time zone like every other date.
 * A branch in another time zone is therefore compared with the clock shifted by the difference of the
 * two zones; for a branch in the company's zone nothing changes.
 */
class BookingClock
{
    public static function now(?int $branchId = null): Carbon
    {
        return now()->addSeconds(self::offsetSeconds($branchId));
    }

    /** Seconds the branch's clock is ahead of the company's. */
    public static function offsetSeconds(?int $branchId): int
    {
        if (! $branchId) {
            return 0;
        }
        $branchZone = TaxProfile::forBranch($branchId)->timezone();
        $companyZone = TaxProfile::forBranch(null)->timezone();
        if ($branchZone === $companyZone) {
            return 0;
        }
        $at = now();

        return $at->copy()->setTimezone($branchZone)->utcOffset() * 60 - $at->copy()->setTimezone($companyZone)->utcOffset() * 60;
    }
}

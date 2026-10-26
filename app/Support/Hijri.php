<?php

namespace App\Support;

use Carbon\Carbon;
use IntlDateFormatter;

/**
 * Hijri (Umm al-Qura, the official Saudi calendar) dates via PHP intl.
 */
class Hijri
{
    /** e.g. "29 ربيع الآخر 1448 هـ" */
    public static function format($date, string $pattern = 'd MMMM y'): ?string
    {
        if (! $date) {
            return null;
        }
        $formatter = new IntlDateFormatter('ar_SA@calendar=islamic-umalqura;numbers=latn', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Riyadh', IntlDateFormatter::TRADITIONAL, $pattern);
        $text = $formatter->format(Carbon::parse($date)->setTimezone('Asia/Riyadh')->startOfDay()->addHours(12));

        return $text === false ? null : $text.' هـ';
    }

    /** Numeric form "1448/04/29". */
    public static function numeric($date): ?string
    {
        return $date ? rtrim(self::format($date, 'y/MM/dd'), ' هـ') : null;
    }

    /** "2026-10-10 (29 ربيع الآخر 1448 هـ)" — both calendars together. */
    public static function both($date): ?string
    {
        return $date ? Carbon::parse($date)->toDateString().' ('.self::format($date).')' : null;
    }
}

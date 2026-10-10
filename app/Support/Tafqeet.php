<?php

namespace App\Support;

/**
 * Amount in Arabic words for documents: "فقط ألف ومائة وخمسون ريال سعودي لا غير".
 */
class Tafqeet
{
    protected const ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];

    protected const TEENS = ['عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];

    protected const TENS = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];

    protected const HUNDREDS = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];

    /** [singular, dual, plural (3-10), singular after 11+] */
    protected const SCALES = [
        1_000_000_000 => ['مليار', 'ملياران', 'مليارات', 'مليار'],
        1_000_000 => ['مليون', 'مليونان', 'ملايين', 'مليون'],
        1_000 => ['ألف', 'ألفان', 'آلاف', 'ألف'],
    ];

    public static function amount(float $amount, string $currency = 'ريال سعودي', string $fraction = 'هللة'): string
    {
        $amount = round(abs($amount), 2);
        $whole = (int) floor($amount);
        $cents = (int) round(($amount - $whole) * 100);

        $text = 'فقط '.($whole ? self::words($whole) : 'صفر').' '.$currency;
        if ($cents) {
            $text .= ' و'.self::words($cents).' '.$fraction;
        }

        return $text.' لا غير';
    }

    public static function words(int $number): string
    {
        if ($number === 0) {
            return 'صفر';
        }

        $parts = [];
        foreach (self::SCALES as $scale => $names) {
            $count = intdiv($number, $scale);
            if ($count) {
                $parts[] = match (true) {
                    $count === 1 => $names[0],
                    $count === 2 => $names[1],
                    $count <= 10 => self::belowThousand($count).' '.$names[2],
                    default => self::belowThousand($count).' '.$names[3],
                };
                $number %= $scale;
            }
        }
        if ($number) {
            $parts[] = self::belowThousand($number);
        }

        return implode(' و', $parts);
    }

    protected static function belowThousand(int $n): string
    {
        $parts = [];
        if ($n >= 100) {
            $parts[] = self::HUNDREDS[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n >= 20) {
            $ones = $n % 10;
            $parts[] = ($ones ? self::ONES[$ones].' و' : '').self::TENS[intdiv($n, 10)];
        } elseif ($n >= 10) {
            $parts[] = self::TEENS[$n - 10];
        } elseif ($n > 0) {
            $parts[] = self::ONES[$n];
        }

        return implode(' و', $parts);
    }
}

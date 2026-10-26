<?php

namespace App\Services\Zatca;

/**
 * TLV encoding of the ZATCA QR code. Each length is a single byte (0-255);
 * longer text values (e.g. long Arabic seller names) are cut on a UTF-8
 * character boundary so the structure always stays valid.
 */
class Tlv
{
    public static function encode(array $tags): string
    {
        $out = '';
        foreach ($tags as $tag => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $value = self::fit((string) $value);
            $out .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($out);
    }

    /** @return array<int, string> */
    public static function decode(string $base64): array
    {
        $raw = base64_decode($base64, true) ?: '';
        $tags = [];
        $i = 0;
        while ($i + 2 <= strlen($raw)) {
            $tag = ord($raw[$i]);
            $length = ord($raw[$i + 1]);
            $tags[$tag] = substr($raw, $i + 2, $length);
            $i += 2 + $length;
        }

        return $tags;
    }

    protected static function fit(string $value): string
    {
        if (strlen($value) <= 255) {
            return $value;
        }

        // Binary values never exceed 255 bytes; text is trimmed on a character boundary.
        return mb_strcut($value, 0, 255, 'UTF-8');
    }
}

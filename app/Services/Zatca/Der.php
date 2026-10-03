<?php

namespace App\Services\Zatca;

/**
 * Minimal DER reader (tag + length) for certificate parsing.
 */
class Der
{
    /** @return array{0:int,1:int} [content length, content offset] for the element at $offset */
    public static function header(string $der, int $offset): array
    {
        $offset++; // tag
        $length = ord($der[$offset++]);
        if ($length & 0x80) {
            $bytes = $length & 0x7F;
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
        }

        return [$length, $offset];
    }
}

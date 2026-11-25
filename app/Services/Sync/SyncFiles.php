<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\Storage;

/**
 * Uploaded files live on each server's own disk, so a row that points at one (an attachment, a signature, a logo) travels by sync
 * but its file does not. This lists where such paths live and moves the files after the rows, both ways, through the head office.
 */
class SyncFiles
{
    /** table => [column, disk] — only columns known to hold a file on that disk. Print-template images (kept inside the template's layout) and other uploads are not synced yet. */
    public const FILES = [
        'attachments' => ['path', 'local'],
        'work_order_attachments' => ['path', 'local'],
        'work_orders' => ['customer_signature_path', 'local'],
        'companies' => ['logo', 'public'],
        'products' => ['image', 'public'],
        // The picture paths sit inside the template's layout (JSON), not in a column of their own.
        'print_templates' => ['layout_data', 'public', 'json'],
    ];

    /** Larger files are left on their own server: they would not fit one request (the attachment limit is 10 MB). */
    public const MAX_BYTES = 5_000_000;

    /** A path is a plain relative storage path: letters, digits and . _ - / only, never going up. */
    public static function safe(?string $path): bool
    {
        return is_string($path) && $path !== '' && strlen($path) < 300
            && preg_match('#^[A-Za-z0-9._\-/]+$#', $path) === 1
            && ! str_contains($path, '..') && ! str_starts_with($path, '/');
    }

    public static function valid(string $table, string $column, string $path): bool
    {
        return (self::FILES[$table][0] ?? null) === $column && self::safe($path);
    }

    /** True when the column holds a JSON layout with picture paths inside, rather than one path. */
    public static function isJson(string $table): bool
    {
        return (self::FILES[$table][2] ?? null) === 'json';
    }

    /**
     * The safe file paths a row points at: its column value, or every picture path inside its JSON layout.
     *
     * @return list<string>
     */
    public static function pathsOf(string $table, mixed $value): array
    {
        if (! self::isJson($table)) {
            return self::safe(is_string($value) ? $value : null) ? [$value] : [];
        }
        $data = is_array($value) ? $value : json_decode((string) $value, true);
        $found = [];
        $walk = function ($node) use (&$walk, &$found) {
            foreach ((array) $node as $key => $item) {
                if (is_array($item)) {
                    $walk($item);
                } elseif ($key === 'src' && is_string($item) && preg_match('#^templates/\d+/[A-Za-z0-9_-]{8,80}\.(png|jpe?g|gif)$#', $item) && self::safe($item)) {
                    $found[$item] = true;
                }
            }
        };
        $walk($data);

        return array_keys($found);
    }

    public static function disk(string $table): string
    {
        return self::FILES[$table][1];
    }

    public static function has(string $table, string $path): bool
    {
        return Storage::disk(self::disk($table))->exists($path);
    }

    public static function read(string $table, string $path): ?string
    {
        $disk = Storage::disk(self::disk($table));
        if (! $disk->exists($path) || $disk->size($path) > self::MAX_BYTES) {
            return null;
        }

        return base64_encode((string) $disk->get($path));
    }

    public static function write(string $table, string $path, string $base64): bool
    {
        $binary = base64_decode($base64, true);
        if ($binary === false || strlen($binary) > self::MAX_BYTES) {
            return false;
        }

        return Storage::disk(self::disk($table))->put($path, $binary);
    }
}

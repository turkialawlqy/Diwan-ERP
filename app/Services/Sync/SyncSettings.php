<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\Artisan;

/**
 * What this server is in the branch synchronisation, kept in one small file under storage/app (which every release shares), so it can
 * be set from the Settings page without anyone editing .env or opening a terminal. The file wins over SYNC_* in .env (which the
 * installers' sync:setup still writes). Holds the branch's token, so it is never shown again and the file is not readable by others.
 */
class SyncSettings
{
    public static function path(): string
    {
        return env('SYNC_SETTINGS_FILE') ?: storage_path('app/sync-node.json');
    }

    /** @return array<string, mixed> */
    public static function load(): array
    {
        $file = self::path();
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $values keys: role (off|hub|branch), node, hub_url, token */
    public static function save(array $values): void
    {
        $file = self::path();
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($file, 0600);
        config(['sync-node.role' => $values['role'] ?? 'off', 'sync-node.node' => (int) ($values['node'] ?? 0),
            'sync-node.hub_url' => $values['hub_url'] ?? null, 'sync-node.token' => $values['token'] ?? null]);
        // A cached configuration would keep the old role for the next requests and for the scheduler.
        try {
            Artisan::call('config:clear');
        } catch (\Throwable) {
        }
    }

    /** One string the head office gives for a new branch: its address, the branch's number and token. */
    public static function connectionCode(string $hubUrl, int $node, string $token): string
    {
        return 'DIWAN1.'.rtrim(strtr(base64_encode(json_encode(['u' => $hubUrl, 'n' => $node, 't' => $token], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    /** @return array{hub_url: string, node: int, token: string}|null */
    public static function parseCode(string $code): ?array
    {
        $code = trim($code);
        if (! str_starts_with($code, 'DIWAN1.')) {
            return null;
        }
        $json = base64_decode(strtr(substr($code, 7), '-_', '+/'), true);
        $d = $json ? json_decode($json, true) : null;
        if (! is_array($d) || ! isset($d['u'], $d['n'], $d['t']) || ! preg_match('#^https?://[^\s]+$#', (string) $d['u']) || (int) $d['n'] < 2 || (int) $d['n'] > 99 || (string) $d['t'] === '') {
            return null;
        }

        return ['hub_url' => rtrim((string) $d['u'], '/'), 'node' => (int) $d['n'], 'token' => (string) $d['t']];
    }
}

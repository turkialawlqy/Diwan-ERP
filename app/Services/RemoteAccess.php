<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * Reaching a local install (Linux or Windows) from the internet without a fixed IP, through a Cloudflare Tunnel or Tailscale.
 * The web app never keeps the secret: it drops a request (mode, domain, tunnel token or Tailscale key) in a folder only the
 * system may read. Linux: file mode 0600, and the root helper `deploy/linux/remote-access.sh` watches it (systemd path unit).
 * Windows: the folder is limited to SYSTEM and Administrators and the app starts the SYSTEM task "Diwan Remote Access", which
 * runs `deploy/windows/remote-access.ps1`. The helper installs and starts the connector, tunes `.env`, deletes the request
 * and writes the status file read here.
 */
class RemoteAccess
{
    public const MODES = ['none', 'cloudflare', 'tailscale'];

    /** The operating system the app runs on (replaceable in tests). */
    public function os(): string
    {
        return PHP_OS_FAMILY;
    }

    /** Installs made by the Linux or Windows installer only: the helper exists nowhere else. */
    public function enabled(): bool
    {
        return (bool) config('app.local_install') && in_array($this->os(), ['Linux', 'Windows'], true);
    }

    protected function queueDir(): string
    {
        return storage_path('app/remote-access-queue');
    }

    protected function statusFile(): string
    {
        return storage_path('app/remote-access-status.json');
    }

    /** @return array{state: string, mode?: string, domain?: string, url?: string, ip?: string, message?: string, at?: int}|null */
    public function status(): ?array
    {
        $json = is_file($this->statusFile()) ? json_decode((string) file_get_contents($this->statusFile()), true) : null;

        return is_array($json) ? $json : null;
    }

    public function running(): bool
    {
        $status = $this->status();

        return $status && in_array($status['state'] ?? '', ['requested', 'running'], true) && ($status['at'] ?? 0) > time() - 900;
    }

    /** What is active now (the last successful change), for the page and the access card. */
    public function active(): array
    {
        $status = $this->status();
        if (! $status || ($status['state'] ?? '') !== 'done') {
            return ['mode' => 'none', 'url' => null, 'ip' => null];
        }

        return ['mode' => $status['mode'] ?? 'none', 'url' => $status['url'] ?? null, 'ip' => $status['ip'] ?? null];
    }

    /**
     * Checks the input strictly: the helper is a root script, so only the characters these values really use pass.
     *
     * @return array{mode: string, domain: string, secret: string}
     */
    public function validate(array $input): array
    {
        $mode = (string) ($input['mode'] ?? '');
        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'اختر طريقة الوصول.']);
        }
        $domain = strtolower(trim((string) ($input['domain'] ?? '')));
        $secret = trim((string) ($input['secret'] ?? ''));

        if ($mode === 'cloudflare') {
            if (! preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
                throw ValidationException::withMessages(['domain' => 'اكتب اسم النطاق كما ضبطته في Cloudflare، مثل erp.example.com.']);
            }
            if (! preg_match('/^[A-Za-z0-9_=.\-]{40,2000}$/', $secret)) {
                throw ValidationException::withMessages(['secret' => 'رمز النفق غير صالح: انسخه كاملًا من لوحة Cloudflare (Zero Trust ← Networks ← Tunnels).']);
            }
        } elseif ($mode === 'tailscale') {
            $domain = '';
            if (! preg_match('/^tskey-[A-Za-z0-9_\-]{10,300}$/', $secret)) {
                throw ValidationException::withMessages(['secret' => 'مفتاح Tailscale غير صالح: يبدأ بـ tskey- ويُنشأ من Settings ← Keys في لوحة Tailscale.']);
            }
        } else {
            $domain = $secret = '';
        }

        return ['mode' => $mode, 'domain' => $domain, 'secret' => $secret];
    }

    /** Hand the (validated) request to the privileged helper. */
    public function request(array $data): void
    {
        File::ensureDirectoryExists($this->queueDir());
        $file = $this->queueDir().'/request';
        if ($this->os() === 'Windows') {
            // Only SYSTEM and Administrators may read the folder (the web workers run as SYSTEM), so the secret is never readable by others.
            @exec('icacls '.escapeshellarg($this->queueDir()).' /inheritance:r /grant:r "SYSTEM:(OI)(CI)F" "Administrators:(OI)(CI)F" 2>&1');
        } else {
            // 0600 before the secret is written, so it is never readable by others, not even briefly.
            touch($file);
            chmod($file, 0600);
        }
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES));
        file_put_contents($this->statusFile(), json_encode(['state' => 'requested', 'mode' => $data['mode'], 'domain' => $data['domain'], 'at' => time()]));
        $this->trigger();
    }

    /** Linux: the systemd path unit fires on the file. Windows: start the SYSTEM task, as the self-update does. */
    protected function trigger(): void
    {
        if ($this->os() !== 'Windows') {
            return;
        }
        $this->ensureWindowsTask();
        @pclose(popen('schtasks /run /tn "Diwan Remote Access"', 'r'));
    }

    /**
     * Installs made before this feature have no "Diwan Remote Access" task, and the update that brings the feature runs the
     * old update script: the web workers run as SYSTEM, so the app registers the task itself the first time it is needed.
     */
    protected function ensureWindowsTask(): void
    {
        @exec('schtasks /query /tn "Diwan Remote Access" 2>&1', $out, $code);
        if ($code === 0) {
            return;
        }
        $task = 'powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "'.$this->windowsScript().'"';
        @exec('schtasks /create /tn "Diwan Remote Access" /tr '.escapeshellarg($task).' /sc ONCE /st 00:00 /ru SYSTEM /rl HIGHEST /f 2>&1');
    }

    /** Through the "current" junction (C:\Diwan\current), so the task survives the old release folder being deleted. */
    public function windowsScript(?string $path = null): string
    {
        $base = str_replace('\\', '/', $path ?? base_path());
        $parent = dirname($base);
        $root = basename($parent) === 'releases' ? dirname($parent).'/current' : $base;

        return str_replace('/', '\\', $root.'/deploy/windows/remote-access.ps1');
    }
}

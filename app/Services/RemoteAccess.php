<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * Reaching a Linux local install from the internet without a fixed IP, through a Cloudflare Tunnel or Tailscale.
 * The web app never gets root and never keeps the secret: it drops a request (mode, domain, tunnel token or Tailscale
 * key, file mode 0600) in a folder the root helper `deploy/linux/remote-access.sh` watches (systemd path unit); the
 * helper installs and starts the connector, tunes `.env`, deletes the request and writes the status file read here.
 */
class RemoteAccess
{
    public const MODES = ['none', 'cloudflare', 'tailscale'];

    /** Linux installs made by the installer only: the helper exists nowhere else. */
    public function enabled(): bool
    {
        return (bool) config('app.local_install') && PHP_OS_FAMILY === 'Linux';
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

    /** Hand the (validated) request to the root helper. */
    public function request(array $data): void
    {
        File::ensureDirectoryExists($this->queueDir());
        $file = $this->queueDir().'/request';
        // 0600 before the secret is written, so it is never readable by others, not even briefly.
        touch($file);
        chmod($file, 0600);
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES));
        file_put_contents($this->statusFile(), json_encode(['state' => 'requested', 'mode' => $data['mode'], 'domain' => $data['domain'], 'at' => time()]));
    }
}

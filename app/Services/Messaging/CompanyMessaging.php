<?php

namespace App\Services\Messaging;

use App\Models\Company;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * A company's own outgoing channels: its SMTP server for e-mail, and an HTTPS gateway each for SMS and WhatsApp.
 * Secrets (SMTP password, gateway tokens) are stored encrypted. The gateways are generic: a URL, a token and a JSON
 * body template with {to} {message} {sender}, so they work with any provider that takes such a request — none is
 * assumed. Without its own settings a company uses the platform's default mailer and the wa.me link.
 */
class CompanyMessaging
{
    public const SECRETS = ['mail_password', 'sms_token', 'whatsapp_token'];

    public const FIELDS = ['mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
        'sms_url', 'sms_token', 'sms_sender', 'sms_body', 'whatsapp_url', 'whatsapp_token', 'whatsapp_body'];

    public const DEFAULT_BODY = '{"to":"{to}","message":"{message}","sender":"{sender}"}';

    public function get(Company $company, string $key): string
    {
        $value = (string) $company->setting($key, '');
        if ($value !== '' && in_array($key, self::SECRETS, true)) {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException) {
                return '';
            }
        }

        return $value;
    }

    /** For the settings page: secrets are never sent back, only whether one is set. */
    public function values(Company $company): array
    {
        $out = [];
        foreach (self::FIELDS as $key) {
            $out[$key] = in_array($key, self::SECRETS, true) ? '' : $this->get($company, $key);
            if (in_array($key, self::SECRETS, true)) {
                $out[$key.'_set'] = $this->get($company, $key) !== '';
            }
        }
        $out['sms_body'] = $out['sms_body'] ?: self::DEFAULT_BODY;
        $out['whatsapp_body'] = $out['whatsapp_body'] ?: self::DEFAULT_BODY;

        return $out;
    }

    public function save(Company $company, array $data): void
    {
        foreach (self::FIELDS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = trim((string) ($data[$key] ?? ''));
            if (in_array($key, self::SECRETS, true)) {
                if ($value === '') {
                    continue; // left empty: keep the stored secret
                }
                $value = Crypt::encryptString($value);
            }
            $company->setSetting($key, $value, 'messaging');
        }
    }

    public function hasSmtp(Company $company): bool
    {
        return $this->get($company, 'mail_host') !== '' && $this->get($company, 'mail_from_address') !== '';
    }

    /** Registers the company's SMTP as a mailer for this request and returns its name (null: use the platform default). */
    public function mailer(Company $company): ?string
    {
        if (! $this->hasSmtp($company)) {
            return null;
        }
        $name = 'company_'.$company->id;
        $encryption = $this->get($company, 'mail_encryption');
        config(["mail.mailers.{$name}" => [
            'transport' => 'smtp',
            'host' => $this->get($company, 'mail_host'),
            'port' => (int) ($this->get($company, 'mail_port') ?: 587),
            'encryption' => in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null,
            'username' => $this->get($company, 'mail_username') ?: null,
            'password' => $this->get($company, 'mail_password') ?: null,
            'timeout' => 15,
        ]]);
        config(['mail.from.address' => $this->get($company, 'mail_from_address'), 'mail.from.name' => $this->get($company, 'mail_from_name') ?: $company->name]);

        return $name;
    }

    public function gatewayConfigured(Company $company, string $channel): bool
    {
        return $this->get($company, $channel.'_url') !== '' && $this->get($company, $channel.'_token') !== '';
    }

    /** @return array{ok: bool, status: ?int, error: ?string} */
    public function send(Company $company, string $channel, string $to, string $message): array
    {
        abort_unless(in_array($channel, ['sms', 'whatsapp'], true), 404);
        $url = $this->get($company, $channel.'_url');
        if (! $this->safeUrl($url)) {
            return ['ok' => false, 'status' => null, 'error' => 'عنوان البوابة يجب أن يكون HTTPS عامًا.'];
        }

        $body = strtr($this->get($company, $channel.'_body') ?: self::DEFAULT_BODY, [
            '{to}' => $this->jsonEscape($to), '{message}' => $this->jsonEscape($message), '{sender}' => $this->jsonEscape($this->get($company, 'sms_sender') ?: $company->name),
        ]);
        $json = json_decode($body, true);
        if (! is_array($json)) {
            return ['ok' => false, 'status' => null, 'error' => 'قالب جسم الطلب ليس JSON صحيحًا.'];
        }

        try {
            $response = Http::withToken($this->get($company, $channel.'_token'))->acceptJson()->timeout(15)->post($url, $json);

            return ['ok' => $response->successful(), 'status' => $response->status(), 'error' => $response->successful() ? null : mb_substr($response->body(), 0, 300)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /** HTTPS only, and never a private, loopback or link-local address (no calls into the server's own network). */
    public function safeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $ips = filter_var($parts['host'], FILTER_VALIDATE_IP) ? [$parts['host']] : (gethostbynamel($parts['host']) ?: []);
        if (! $ips) {
            return app()->runningUnitTests(); // an unresolvable name is only tolerated under test (fakes)
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    protected function jsonEscape(string $value): string
    {
        return substr(json_encode($value, JSON_UNESCAPED_UNICODE), 1, -1);
    }
}

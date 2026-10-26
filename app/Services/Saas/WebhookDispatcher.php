<?php

namespace App\Services\Saas;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;
use App\Services\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Outgoing webhooks: events are queued after the database commit and
 * delivered with an HMAC-SHA256 signature of the body.
 */
class WebhookDispatcher
{
    public function dispatch(string $event, array $payload): void
    {
        $companyId = app(TenantManager::class)->getTenantId();
        if (! $companyId) {
            return;
        }

        DB::afterCommit(function () use ($event, $payload, $companyId) {
            WebhookEndpoint::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->get()
                ->filter(fn ($endpoint) => in_array($event, $endpoint->events ?? [], true) || in_array('*', $endpoint->events ?? [], true))
                ->each(fn ($endpoint) => DeliverWebhook::dispatch($endpoint->id, $event, $payload, (string) Str::uuid()));
        });
    }

    /**
     * Webhook targets must be public HTTPS endpoints (no internal network
     * access). Returns the vetted IP the request must be pinned to, so a DNS
     * answer that changes between the check and the call (rebinding) cannot
     * reach an internal address.
     */
    public static function assertSafeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        $allowHttp = app()->environment(['local', 'testing']);

        if (! in_array($parts['scheme'] ?? '', $allowHttp ? ['https', 'http'] : ['https'], true) || $host === '' || isset($parts['user'])) {
            throw ValidationException::withMessages(['url' => 'يجب أن يكون الرابط HTTPS صالحاً.']);
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw ValidationException::withMessages(['url' => 'لا يُسمح بعناوين الشبكات الداخلية.']);
            }
        }

        if (app()->environment('testing') && ! filter_var($host, FILTER_VALIDATE_IP)) {
            return null; // tests never hit DNS
        }
        if (! $ips) {
            throw ValidationException::withMessages(['url' => 'تعذر الوصول إلى اسم النطاق.']);
        }

        return $ips[0];
    }

    /** IPv4 and IPv6 addresses of a host. */
    protected static function resolve(string $host): array
    {
        if (app()->environment('testing')) {
            return [];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $packed = inet_pton($ip);
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is checked as the IPv4 it carries.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return self::isPublicIp(inet_ntop(substr($packed, 12)));
        }

        $blocked = strlen($packed) === 4
            ? ['0.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4']
            : ['fc00::/7', 'fe80::/10', '::/128', '::1/128', '64:ff9b::/96', '2001:db8::/32', 'ff00::/8'];
        foreach ($blocked as $cidr) {
            if (self::inRange($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    protected static function inRange(string $packed, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netPacked = inet_pton($net);
        if (strlen($netPacked) !== strlen($packed)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($netPacked[$bytes]) & $mask);
    }
}

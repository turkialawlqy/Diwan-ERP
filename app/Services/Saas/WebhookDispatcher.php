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
     * Webhook targets must be public HTTPS endpoints (no internal network access).
     */
    public static function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $allowHttp = app()->environment(['local', 'testing']);

        if (! in_array($parts['scheme'] ?? '', $allowHttp ? ['https', 'http'] : ['https'], true) || $host === '' || isset($parts['user'])) {
            throw ValidationException::withMessages(['url' => 'يجب أن يكون الرابط HTTPS صالحاً.']);
        }

        if (app()->environment('testing')) {
            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            throw ValidationException::withMessages(['url' => 'تعذر الوصول إلى اسم النطاق.']);
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ValidationException::withMessages(['url' => 'لا يُسمح بعناوين الشبكات الداخلية.']);
            }
        }
    }
}

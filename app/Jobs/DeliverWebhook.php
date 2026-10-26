<?php

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use App\Services\Saas\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $endpointId, public string $event, public array $payload, public string $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function backoff(): array
    {
        return [30, 120, 600, 3600];
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($this->endpointId);
        if (! $endpoint || ! $endpoint->is_active) {
            return;
        }

        $ip = WebhookDispatcher::assertSafeUrl($endpoint->url);
        $parts = parse_url($endpoint->url);
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);
        // Connect to the address that was vetted, not to a fresh DNS answer.
        $pin = $ip ? ['curl' => [CURLOPT_RESOLVE => [trim($parts['host'], '[]').':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]]] : [];

        $body = json_encode(['id' => $this->deliveryId, 'event' => $this->event, 'created_at' => now()->toIso8601String(), 'data' => $this->payload], JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret);

        $response = Http::timeout(10)->withoutRedirecting()->withOptions($pin)->withHeaders([
            'Content-Type' => 'application/json',
            'X-Diwan-Event' => $this->event,
            'X-Diwan-Delivery' => $this->deliveryId,
            'X-Diwan-Timestamp' => $timestamp,
            'X-Diwan-Signature' => 'sha256='.$signature,
        ])->withBody($body, 'application/json')->post($endpoint->url);

        $endpoint->forceFill([
            'last_delivery_at' => now(),
            'last_status' => $response->status(),
            'failures' => $response->successful() ? 0 : $endpoint->failures + 1,
            // Endpoints failing persistently are switched off.
            'is_active' => $response->successful() || $endpoint->failures < 50,
        ])->save();

        if (! $response->successful() && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[min($this->attempts() - 1, 3)]);
        }
    }
}

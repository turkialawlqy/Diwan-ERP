<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\Http;

class HttpSyncTransport implements SyncTransport
{
    public function __construct(protected string $hubUrl, protected string $token) {}

    public function post(string $path, array $payload): array
    {
        $response = Http::withToken($this->token)->acceptJson()->timeout(60)->post(rtrim($this->hubUrl, '/').'/api/sync/'.$path, $payload);
        if ($response->failed()) {
            throw new \RuntimeException('The head office answered '.$response->status().' to '.$path.': '.substr((string) $response->body(), 0, 300));
        }

        return (array) $response->json();
    }
}

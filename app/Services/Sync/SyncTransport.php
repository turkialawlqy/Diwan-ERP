<?php

namespace App\Services\Sync;

/** How a branch talks to the head office: one call returns the decoded JSON answer or throws. */
interface SyncTransport
{
    /** @param  array<string, mixed>  $payload */
    public function post(string $path, array $payload): array;
}

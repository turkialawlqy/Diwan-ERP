<?php

namespace App\Services\Sync;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The head office's side of the sync: which branches may connect, what they may send, and what each is sent back.
 * A branch is known only by its token (kept hashed). It can write only changes it made itself, and only into its own company.
 */
class SyncHub
{
    protected SyncEngine $engine;

    public function __construct(protected string $connection, ?SyncEngine $engine = null)
    {
        $this->engine = $engine ?? new SyncEngine($connection, 'hub');
    }

    protected function db(): Connection
    {
        return DB::connection($this->connection);
    }

    /** @return string the token, shown once */
    public function register(int $nodeId, string $name, ?int $companyId = null): string
    {
        $token = Str::random(48);
        $row = ['name' => $name, 'token_hash' => hash('sha256', $token), 'company_id' => $companyId];
        $this->db()->table('sync_nodes')->updateOrInsert(['node_id' => $nodeId], $row);

        return $token;
    }

    public function authenticate(?string $token): ?object
    {
        if (! $token) {
            return null;
        }

        return $this->db()->table('sync_nodes')->where('token_hash', hash('sha256', $token))->first();
    }

    /** The two ends must run the same release: a different one may have a different schema. */
    public function handshake(object $node, array $in): array
    {
        $mine = trim((string) @file_get_contents(base_path('VERSION')));
        $theirs = (string) ($in['version'] ?? '');
        $this->db()->table('sync_nodes')->where('node_id', $node->node_id)->update(['last_seen_at' => now()]);

        return ['ok' => $mine === $theirs, 'version' => $mine, 'node_id' => (int) $node->node_id];
    }

    /** @return array{applied: int, skipped: int, conflicts: int, refused: int} */
    public function receive(object $node, array $changes): array
    {
        $accepted = [];
        $refused = 0;
        foreach ($changes as $change) {
            $company = $change['payload']['company_id'] ?? null;
            // A branch writes only what it made itself, and only in its own company.
            $foreign = (int) ($change['node_id'] ?? 0) !== (int) $node->node_id
                || ($node->company_id && $company !== null && (int) $company !== (int) $node->company_id);
            $foreign ? $refused++ : $accepted[] = $change;
        }

        // One writer at a time: ids reach the feed in the order they commit, so no reader's cursor can pass an uncommitted one.
        $result = $this->db()->transaction(function () use ($accepted) {
            $this->db()->table('sync_state')->where('id', 1)->lockForUpdate()->first();

            return $this->engine->apply($accepted);
        });

        return $result + ['refused' => $refused];
    }

    /** @return array{changes: list<array<string, mixed>>, next: int, more: bool} everything after $after except the branch's own changes */
    public function feed(object $node, int $after, int $limit = 200): array
    {
        // Asking from $after means everything up to it is in the branch's hands: its feed can be pruned that far.
        $this->db()->table('sync_nodes')->where('node_id', $node->node_id)->update(['acknowledged' => max((int) $node->acknowledged, $after), 'last_seen_at' => now()]);
        $ceiling = $this->engine->ceiling();   // read first: whatever it covers is visible to the query below
        $changes = $this->engine->pending($after, $limit, (int) $node->node_id);
        // Rows of the branch's own are skipped by the query, so the cursor moves past them too.
        $next = $changes ? max(array_column($changes, 'feed')) : $after;
        $more = count($changes) >= $limit;
        if (! $more) {
            $next = max($next, $ceiling);
        }

        return ['changes' => $changes, 'next' => $next, 'more' => $more];
    }
}

<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;

/**
 * The branch's side: sends its feed to the head office, then receives the head office's feed, each from a cursor kept in
 * sync_cursors. Safe to stop anywhere (a dropped link, a crash): the cursor moves only after the other side confirmed, and a
 * change sent twice is applied once.
 */
class SyncClient
{
    protected SyncEngine $engine;

    public function __construct(protected string $connection, protected SyncTransport $transport, protected int $batch = 200, ?SyncEngine $engine = null)
    {
        $this->engine = $engine ?? new SyncEngine($connection, 'branch');
    }

    /** @return array{pushed: int, pulled: int, applied: int, conflicts: int} */
    public function run(): array
    {
        $db = DB::connection($this->connection);
        $db->table('sync_cursors')->insertOrIgnore(['peer' => 'hub', 'pushed' => 0, 'pulled' => 0]);
        $cursor = $db->table('sync_cursors')->where('peer', 'hub')->first();
        $version = trim((string) @file_get_contents(base_path('VERSION')));

        $hello = $this->transport->post('handshake', ['version' => $version]);
        if (! ($hello['ok'] ?? false)) {
            throw new \RuntimeException('The head office runs release '.($hello['version'] ?? '?')." and this branch runs {$version}: update the branch first.");
        }

        $out = ['pushed' => 0, 'pulled' => 0, 'applied' => 0, 'conflicts' => 0];
        $pushed = (int) $cursor->pushed;
        while ($changes = $this->engine->pending($pushed, $this->batch)) {
            $this->transport->post('push', ['changes' => $changes]);
            $pushed = max(array_column($changes, 'feed'));
            $db->table('sync_cursors')->where('peer', 'hub')->update(['pushed' => $pushed]);
            $out['pushed'] += count($changes);
        }

        $pulled = (int) $cursor->pulled;
        do {
            $answer = $this->transport->post('pull', ['after' => $pulled, 'limit' => $this->batch]);
            $result = $this->engine->apply($answer['changes'] ?? []);
            $pulled = (int) ($answer['next'] ?? $pulled);
            $db->table('sync_cursors')->where('peer', 'hub')->update(['pulled' => $pulled]);
            $out['pulled'] += count($answer['changes'] ?? []);
            $out['applied'] += $result['applied'];
            $out['conflicts'] += $result['conflicts'];
        } while ($answer['more'] ?? false);

        return $out;
    }
}

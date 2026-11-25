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

        if (! (new SyncBootstrap($this->connection, $this->transport))->done()) {
            throw new \RuntimeException('The first copy of the head office data did not finish: run it again from Settings, Sync.');
        }
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

        $out += $this->syncFiles();

        return $out;
    }

    /**
     * After the rows: for each table that points at uploaded files, walk its rows from a cursor and move the files — a file this
     * server has and the head office lacks is sent, one this server lacks is fetched. The cursor stops at the first file that could
     * not be fetched yet (its row can arrive before the sender's upload does) and gives up on one older than three days.
     *
     * @return array{files_sent: int, files_fetched: int}
     */
    public function syncFiles(): array
    {
        $db = DB::connection($this->connection);
        $out = ['files_sent' => 0, 'files_fetched' => 0];
        foreach (SyncFiles::FILES as $table => [$column]) {
            if (! $db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $peer = "files:{$table}.{$column}";
            $db->table('sync_cursors')->insertOrIgnore(['peer' => $peer, 'pushed' => 0, 'pulled' => 0]);
            $cursor = (int) $db->table('sync_cursors')->where('peer', $peer)->value('pushed');
            $stuck = null;
            $last = $cursor;
            $rows = $db->table($table)->where('id', '>', $cursor)->whereNotNull($column)->orderBy('id')->limit(100)->get(['id', $column]);
            foreach ($rows as $row) {
                $last = $row->id;
            }
            $stuck = $this->moveFiles($table, $column, $rows, $out);
            // A picture replaced on a row the cursor has already passed (a product edited, a template redrawn) is caught here: rows
            // changed in the last two days are looked at every round, whatever the cursor says.
            if ($db->getSchemaBuilder()->hasColumn($table, 'updated_at')) {
                $recent = $db->table($table)->where('id', '<=', $cursor)->whereNotNull($column)->where('updated_at', '>=', now()->subDays(2))->orderBy('id')->limit(200)->get(['id', $column]);
                $this->moveFiles($table, $column, $recent, $out);
            }
            // Rows left behind a stuck file are looked at again, but never for ever: the cursor passes a file older than three days.
            $next = $stuck !== null ? $stuck - 1 : $last;
            if ($stuck !== null && $db->getSchemaBuilder()->hasColumn($table, 'created_at')
                && $db->table($table)->where('id', $stuck)->where('created_at', '<', now()->subDays(3))->exists()) {
                $next = $stuck;
            }
            $db->table('sync_cursors')->where('peer', $peer)->update(['pushed' => max($cursor, $next)]);
        }

        return $out;
    }

    /**
     * Sends / fetches the files the given rows point at. Returns the id of the first row whose file could not be fetched yet.
     *
     * @param  array{files_sent: int, files_fetched: int}  $out
     */
    protected function moveFiles(string $table, string $column, $rows, array &$out): ?int
    {
        $perRow = $rows->mapWithKeys(fn ($r) => [$r->id => SyncFiles::pathsOf($table, $r->{$column})]);
        $paths = $perRow->flatten()->unique()->map(fn ($p) => ['table' => $table, 'path' => $p])->values()->all();
        $lacking = $paths ? array_flip($this->transport->post('files-missing', ['items' => $paths])['missing'] ?? []) : [];
        $stuck = null;
        foreach ($rows as $row) {
            foreach ($perRow[$row->id] as $path) {
                if (SyncFiles::has($table, $path)) {
                    if (isset($lacking[$path]) && ($content = SyncFiles::read($table, $path)) !== null) {
                        $this->transport->post('files-put', ['table' => $table, 'path' => $path, 'content' => $content]);
                        $out['files_sent']++;
                    }

                    continue;
                }
                $got = $this->transport->post('files-get', ['table' => $table, 'path' => $path])['content'] ?? null;
                if ($got && SyncFiles::write($table, $path, $got)) {
                    $out['files_fetched']++;
                } elseif ($stuck === null) {
                    $stuck = $row->id;
                }
            }
        }

        return $stuck;
    }
}

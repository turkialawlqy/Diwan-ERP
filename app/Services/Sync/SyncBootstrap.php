<?php

namespace App\Services\Sync;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The first copy of a new branch: replaces this server's business data with the head office's, table by table, so no backup has to be
 * restored by hand. Local data of this server (sessions, queue, caches, the sync tables themselves) is left alone. The normal feed then
 * carries everything the head office changed meanwhile. Marks itself in sync_cursors ('snapshot') so a half-done copy is never synced.
 */
class SyncBootstrap
{
    public function __construct(protected string $connection, protected SyncTransport $transport) {}

    public function done(): bool
    {
        $row = DB::connection($this->connection)->table('sync_cursors')->where('peer', 'snapshot')->first();

        return ! $row || (int) $row->pulled === 1;
    }

    /** @return array{tables: int, rows: int} */
    public function run(?callable $progress = null): array
    {
        $db = DB::connection($this->connection);
        $info = $this->transport->post('snapshot-info', []);
        $db->table('sync_cursors')->updateOrInsert(['peer' => 'snapshot'], ['pushed' => 0, 'pulled' => 0]);   // 0 = not finished
        $total = ['tables' => 0, 'rows' => 0];

        $this->withoutChecks($db, function () use ($db, $info, &$total, $progress) {
            $db->table('sync_state')->where('id', 1)->update(['applying' => 1]);   // the capture triggers stay quiet
            try {
                foreach ($info['tables'] as $table) {
                    if (! $db->getSchemaBuilder()->hasTable($table)) {
                        continue;
                    }
                    $db->table($table)->delete();
                    $after = 0;
                    do {
                        $page = $this->transport->post('snapshot-page', ['table' => $table, 'after' => $after, 'limit' => 200]);
                        foreach (array_chunk($page['rows'], 100) as $chunk) {
                            $db->table($table)->insert($chunk);
                        }
                        $total['rows'] += count($page['rows']);
                        $after = (int) $page['next'];
                    } while ($page['more'] ?? false);
                    $total['tables']++;
                    $progress && $progress($table, $total);
                }
            } finally {
                $db->table('sync_state')->where('id', 1)->update(['applying' => 0]);
            }
        });

        // Nothing here is this server's own change yet, and everything the head office did before the copy started is already in it.
        $db->table('sync_outbox')->delete();
        $db->table('sync_applied')->delete();
        $db->table('sync_cursors')->where('peer', 'hub')->delete();
        $db->table('sync_cursors')->insert(['peer' => 'hub', 'pushed' => 0, 'pulled' => (int) $info['ceiling']]);
        $db->table('sync_cursors')->where('peer', 'snapshot')->update(['pulled' => 1]);

        return $total;
    }

    protected function withoutChecks(Connection $db, callable $work): void
    {
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        $mysql ? $db->statement('SET FOREIGN_KEY_CHECKS=0') : $db->statement('PRAGMA foreign_keys = OFF');
        try {
            $work();
        } finally {
            $mysql ? $db->statement('SET FOREIGN_KEY_CHECKS=1') : $db->statement('PRAGMA foreign_keys = ON');
        }
    }
}

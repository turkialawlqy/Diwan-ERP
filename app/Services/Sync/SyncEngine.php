<?php

namespace App\Services\Sync;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Prototype of the merge: reads this node's change feed and applies the feed of another node.
 *
 * Who may write what comes from config/sync.php (master: the head office; owned: the node that made the row; derived and local never travel).
 * Applying is idempotent (a change is identified by the node that made it and its sequence number), never echoes back out (the
 * triggers are switched off while it writes), and anything it cannot apply safely goes to sync_conflicts instead of overwriting data.
 */
class SyncEngine
{
    public function __construct(protected string $connection, protected string $role = 'branch') {}

    protected function db(): Connection
    {
        return DB::connection($this->connection);
    }

    public function nodeId(): int
    {
        return (int) $this->db()->table('sync_state')->where('id', 1)->value('node_id');
    }

    public function join(int $nodeId): void
    {
        SyncSchema::create($this->db());
        $this->db()->table('sync_state')->updateOrInsert(['id' => 1], ['node_id' => $nodeId, 'applying' => 0]);
    }

    /** @return list<array<string, mixed>> this node's changes after $afterSeq, oldest first, only for tables that travel */
    public function pending(int $afterSeq = 0, int $limit = 500): array
    {
        $travels = array_merge(config('sync.master', []), config('sync.owned', []));

        return $this->db()->table('sync_outbox')->where('id', '>', $afterSeq)->whereIn('table_name', $travels)
            ->orderBy('id')->limit($limit)->get()
            ->map(fn ($r) => ['node_id' => (int) $r->node_id, 'seq' => (int) $r->id, 'table' => $r->table_name, 'pk' => (int) $r->pk, 'op' => $r->op,
                'payload' => $r->payload ? json_decode($r->payload, true) : null, 'at' => $r->created_at])->all();
    }

    /** @return array{applied: int, skipped: int, conflicts: int} */
    public function apply(array $changes): array
    {
        $result = ['applied' => 0, 'skipped' => 0, 'conflicts' => 0];
        $db = $this->db();
        foreach ($changes as $change) {
            $outcome = $db->transaction(function () use ($db, $change) {
                if ($change['node_id'] === $this->nodeId()) {
                    return 'skipped'; // our own change coming back
                }
                if ($db->table('sync_applied')->where('node_id', $change['node_id'])->where('seq', $change['seq'])->exists()) {
                    return 'skipped';
                }
                $class = $this->classOf($change['table']);
                $outcome = $class === null ? 'skipped' : $this->write($db, $change, $class);
                $db->table('sync_applied')->insert(['node_id' => $change['node_id'], 'seq' => $change['seq']]);

                return $outcome;
            });
            $result[$outcome === 'applied' ? 'applied' : ($outcome === 'conflict' ? 'conflicts' : 'skipped')]++;
        }

        return $result;
    }

    protected function classOf(string $table): ?string
    {
        foreach (['master', 'owned'] as $class) {
            if (in_array($table, config("sync.{$class}", []), true)) {
                return $class;
            }
        }

        return null;
    }

    protected function write(Connection $db, array $change, string $class): string
    {
        $table = $change['table'];
        $pk = $change['pk'];
        $exists = $db->table($table)->where('id', $pk)->exists();

        // The head office alone writes master data: a branch's edit of an existing master row waits for a person.
        if ($class === 'master' && $this->role === 'hub' && $change['op'] !== 'I') {
            return $this->conflict($db, $change, 'master_edited_on_branch');
        }
        // An id taken by another row: never overwrite it.
        if ($change['op'] === 'I' && $exists) {
            return $this->conflict($db, $change, 'id_collision');
        }

        $db->table('sync_state')->where('id', 1)->update(['applying' => 1]);
        // A change may arrive before the row it points to (another node's feed): foreign keys are checked again by the next full audit.
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        if ($mysql) {
            $db->statement('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            if ($change['op'] === 'D') {
                $db->table($table)->where('id', $pk)->delete();
            } else {
                $row = $change['payload'];
                // An update that arrives before its insert is still applied (out-of-order delivery).
                $db->table($table)->upsert([$row], ['id'], array_values(array_diff(array_keys($row), ['id'])));
            }
        } finally {
            if ($mysql) {
                $db->statement('SET FOREIGN_KEY_CHECKS=1');
            }
            $db->table('sync_state')->where('id', 1)->update(['applying' => 0]);
        }

        return 'applied';
    }

    protected function conflict(Connection $db, array $change, string $reason): string
    {
        $db->table('sync_conflicts')->insert(['node_id' => $change['node_id'], 'seq' => $change['seq'], 'table_name' => $change['table'], 'pk' => $change['pk'],
            'op' => $change['op'], 'payload' => $change['payload'] ? json_encode($change['payload'], JSON_UNESCAPED_UNICODE) : null, 'reason' => $reason]);

        return 'conflict';
    }
}

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

    /** The row that owns a file path, if this node may touch it (a node tied to one company reaches only that company's rows). */
    protected function fileOwner(object $node, string $table, string $column, string $path): ?object
    {
        if (! SyncFiles::valid($table, $column, $path)) {
            return null;
        }
        $query = $this->db()->table($table);
        // A JSON layout is searched for the path as text, then checked path by path.
        // (JSON text usually has the slashes escaped as \/ : both spellings are looked for.)
        $rows = SyncFiles::isJson($table)
            ? $query->where(fn ($q) => $q->where($column, 'like', '%'.$path.'%')->orWhere($column, 'like', '%'.str_replace('/', '\\/', $path).'%'))->get()
            : $query->where($column, $path)->get();
        foreach ($rows as $row) {
            if (! in_array($path, SyncFiles::pathsOf($table, $row->{$column}), true)) {
                continue;
            }
            $company = $table === 'companies' ? $row->id : ($row->company_id ?? null);
            if (! ($node->company_id && $company !== null && (int) $company !== (int) $node->company_id)) {
                return $row;
            }
        }

        return null;
    }

    /** @param list<array{table: string, path: string}> $items @return list<string> the paths whose file this server does not have */
    public function filesMissing(object $node, array $items): array
    {
        $missing = [];
        foreach ($items as $item) {
            [$table, $path] = [$item['table'] ?? '', $item['path'] ?? ''];
            $column = SyncFiles::FILES[$table][0] ?? '';
            if ($this->fileOwner($node, $table, $column, $path) && ! SyncFiles::has($table, $path)) {
                $missing[] = $path;
            }
        }

        return $missing;
    }

    public function filePut(object $node, string $table, string $path, string $base64): bool
    {
        $column = SyncFiles::FILES[$table][0] ?? '';

        return $this->fileOwner($node, $table, $column, $path) !== null && SyncFiles::write($table, $path, $base64);
    }

    public function fileGet(object $node, string $table, string $path): ?string
    {
        $column = SyncFiles::FILES[$table][0] ?? '';

        return $this->fileOwner($node, $table, $column, $path) ? SyncFiles::read($table, $path) : null;
    }

    /**
     * The tables a new branch copies (in the order the head office lists them) and the feed position the copy starts from: whatever
     * the head office changes after that position reaches the branch by the normal feed, so nothing is missed while it copies.
     *
     * @return array{tables: list<string>, ceiling: int}
     */
    public function snapshotInfo(object $node): array
    {
        $schema = $this->db()->getSchemaBuilder();
        $tables = array_values(array_filter(array_merge(config('sync.master', []), config('sync.owned', [])), fn ($t) => $schema->hasTable($t)));

        return ['tables' => $tables, 'ceiling' => $this->engine->ceiling()];
    }

    /**
     * One page of one table. A table with an `id` is read from the id after $after; a link table (no id) by offset. A branch tied to one
     * company gets only that company's rows where the table says whose they are.
     *
     * @return array{rows: list<array<string, mixed>>, next: int, more: bool}
     */
    public function snapshotPage(object $node, string $table, int $after, int $limit = 200): array
    {
        $info = $this->snapshotInfo($node);
        abort_unless(in_array($table, $info['tables'], true), 404);
        $limit = max(1, min($limit, 500));
        $schema = $this->db()->getSchemaBuilder();
        $query = $this->db()->table($table);
        if ($node->company_id) {
            $table === 'companies' ? $query->where('id', $node->company_id) : ($schema->hasColumn($table, 'company_id') ? $query->where('company_id', $node->company_id) : null);
        }

        if ($schema->hasColumn($table, 'id')) {
            $rows = $query->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
            $more = $rows->count() > $limit;
            $rows = $rows->take($limit)->map(fn ($r) => (array) $r)->values();

            return ['rows' => $rows->all(), 'next' => (int) ($rows->last()['id'] ?? $after), 'more' => $more];
        }
        foreach ($schema->getColumnListing($table) as $column) {
            $query->orderBy($column);
        }
        $rows = $query->offset($after)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;

        return ['rows' => $rows->take($limit)->map(fn ($r) => (array) $r)->values()->all(), 'next' => $after + min($rows->count(), $limit), 'more' => $more];
    }
}

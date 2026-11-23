<?php

namespace App\Services\Sync;

use Illuminate\Database\Connection;

/**
 * Change capture in the database itself, so nothing that writes a row can skip it (Eloquent, saveQuietly, raw SQL, hard delete).
 * Each trigger writes the new row as JSON into sync_outbox, unless sync_state.applying = 1 (a merge writing rows from another node).
 * SQLite (tests, e2e) and MySQL / MariaDB (the real installs) are supported; the MySQL form could not be run in the prototype's sandbox.
 */
class SyncTriggers
{
    /**
     * @return list<string> the tables left without capture: they have no single `id` key (link tables such as role_has_permissions),
     *                      so the engine cannot name their rows yet; they need their own rule in the next phase
     */
    public static function install(Connection $db, array $tables, string $pk = 'id'): array
    {
        $schema = $db->getSchemaBuilder();
        $skipped = [];
        foreach ($tables as $table) {
            $columns = $schema->getColumnListing($table);
            if (! in_array($pk, $columns, true)) {
                $skipped[] = $table;

                continue;
            }
            foreach (self::statements($db->getDriverName(), $table, $columns, $pk) as $sql) {
                $db->unprepared($sql);
            }
        }

        return $skipped;
    }

    public static function remove(Connection $db, array $tables): void
    {
        foreach ($tables as $table) {
            foreach (['ai', 'au', 'ad'] as $suffix) {
                $db->unprepared("DROP TRIGGER IF EXISTS sync_{$table}_{$suffix}");
            }
        }
    }

    /** @return list<string> */
    public static function statements(string $driver, string $table, array $columns, string $pk = 'id'): array
    {
        $quote = $driver === 'sqlite' ? '"' : '`';
        $fn = $driver === 'sqlite' ? 'json_object' : 'JSON_OBJECT';
        $json = fn (string $row) => $fn.'('.implode(', ', array_map(fn ($c) => "'{$c}', {$row}.{$quote}{$c}{$quote}", $columns)).')';
        $insert = fn (string $op, string $row, bool $payload) => "INSERT INTO sync_outbox (node_id, table_name, pk, op, payload) SELECT node_id, '{$table}', {$row}.{$pk}, '{$op}', "
            .($payload ? $json($row) : 'NULL').' FROM sync_state WHERE id = 1';

        $out = [];
        foreach ([['ai', 'INSERT', 'I', 'NEW', true], ['au', 'UPDATE', 'U', 'NEW', true], ['ad', 'DELETE', 'D', 'OLD', false]] as [$suffix, $event, $op, $row, $payload]) {
            $name = "sync_{$table}_{$suffix}";
            if ($driver === 'sqlite') {
                $out[] = "CREATE TRIGGER IF NOT EXISTS {$name} AFTER {$event} ON {$table} WHEN (SELECT applying FROM sync_state WHERE id = 1) = 0 BEGIN ".$insert($op, $row, $payload).'; END';
            } else {
                $out[] = "DROP TRIGGER IF EXISTS {$name}";
                $out[] = "CREATE TRIGGER {$name} AFTER {$event} ON {$table} FOR EACH ROW BEGIN IF (SELECT applying FROM sync_state WHERE id = 1) = 0 THEN ".$insert($op, $row, $payload).'; END IF; END';
            }
        }

        return $out;
    }
}

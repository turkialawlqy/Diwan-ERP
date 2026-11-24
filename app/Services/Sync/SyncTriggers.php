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
     * Link tables (role_has_permissions...) have no single `id`: their rows are captured whole with pk = 0 (a row is its own key),
     * as an insert or a delete; an update travels as delete-old + insert-new. Only a table with no `id` AND the pk asked for is skipped.
     *
     * @return list<string> the tables left without capture (none with the default `id` key: link tables are handled)
     */
    public static function install(Connection $db, array $tables, string $pk = 'id'): array
    {
        $schema = $db->getSchemaBuilder();
        $skipped = [];
        foreach ($tables as $table) {
            $columns = $schema->getColumnListing($table);
            if (! in_array($pk, $columns, true) && $pk !== 'id') {
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
        $link = ! in_array($pk, $columns, true);
        $json = fn (string $row) => $fn.'('.implode(', ', array_map(fn ($c) => "'{$c}', {$row}.{$quote}{$c}{$quote}", $columns)).')';
        $pkExpr = fn (string $row) => $link ? '0' : "{$row}.{$pk}";
        $insert = fn (string $op, string $row, bool $payload) => "INSERT INTO sync_outbox (node_id, table_name, pk, op, payload) SELECT node_id, '{$table}', {$pkExpr($row)}, '{$op}', "
            .($payload ? $json($row) : 'NULL').' FROM sync_state WHERE id = 1';

        $out = [];
        // [suffix, event, statements run in the trigger body as [op, row, withPayload]]
        $defs = $link
            ? [['ai', 'INSERT', [['I', 'NEW', true]]], ['au', 'UPDATE', [['D', 'OLD', true], ['I', 'NEW', true]]], ['ad', 'DELETE', [['D', 'OLD', true]]]]
            : [['ai', 'INSERT', [['I', 'NEW', true]]], ['au', 'UPDATE', [['U', 'NEW', true]]], ['ad', 'DELETE', [['D', 'OLD', false]]]];
        foreach ($defs as [$suffix, $event, $parts]) {
            $name = "sync_{$table}_{$suffix}";
            $body = implode('; ', array_map(fn ($p) => $insert($p[0], $p[1], $p[2]), $parts)).';';
            if ($driver === 'sqlite') {
                $out[] = "CREATE TRIGGER IF NOT EXISTS {$name} AFTER {$event} ON {$table} WHEN (SELECT applying FROM sync_state WHERE id = 1) = 0 BEGIN {$body} END";
            } else {
                $out[] = "DROP TRIGGER IF EXISTS {$name}";
                $out[] = "CREATE TRIGGER {$name} AFTER {$event} ON {$table} FOR EACH ROW BEGIN IF (SELECT applying FROM sync_state WHERE id = 1) = 0 THEN {$body} END IF; END";
            }
        }

        return $out;
    }
}

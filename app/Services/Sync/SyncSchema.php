<?php

namespace App\Services\Sync;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * The tables every node keeps for synchronisation (prototype). They are `local` in config/sync.php: they never leave their server.
 *  sync_state     one row: this node's id, and a flag the triggers read so rows written by a merge are not captured again.
 *  sync_outbox    the change feed: one row per insert / update / delete of a replicated table, written by triggers (so raw SQL,
 *                 saveQuietly and hard deletes are captured too).
 *  sync_applied   what this node has already applied from other nodes, so the same change can be delivered twice safely.
 *  sync_conflicts changes that were NOT applied and wait for a person (an id taken by another row, a master row edited on a branch).
 */
class SyncSchema
{
    public static function create(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('sync_state')) {
            $schema->create('sync_state', function (Blueprint $t) {
                $t->unsignedTinyInteger('id')->primary();
                $t->unsignedInteger('node_id');
                $t->unsignedTinyInteger('applying')->default(0);
            });
        }
        if (! $schema->hasTable('sync_outbox')) {
            $schema->create('sync_outbox', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('node_id');
                $t->string('table_name', 100);
                $t->unsignedBigInteger('pk');
                $t->char('op', 1);
                $t->longText('payload')->nullable();
                // A change the head office relays from another node keeps that node's own sequence number (its identity).
                $t->unsignedBigInteger('origin_seq')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['node_id', 'id']);
            });
        }
        if (! $schema->hasTable('sync_applied')) {
            $schema->create('sync_applied', function (Blueprint $t) {
                $t->unsignedInteger('node_id');
                $t->unsignedBigInteger('seq');
                $t->timestamp('applied_at')->useCurrent();
                $t->primary(['node_id', 'seq']);
            });
        }
        if (! $schema->hasTable('sync_cursors')) {
            // On a branch: how far its feed was pushed to the head office and how far the head office's feed was pulled.
            $schema->create('sync_cursors', function (Blueprint $t) {
                $t->string('peer', 60)->primary();
                $t->unsignedBigInteger('pushed')->default(0);
                $t->unsignedBigInteger('pulled')->default(0);
            });
        }
        if (! $schema->hasTable('sync_nodes')) {
            // On the head office: the branches allowed to sync, by a hashed token, and how far each has pulled.
            $schema->create('sync_nodes', function (Blueprint $t) {
                $t->unsignedInteger('node_id')->primary();
                $t->string('name', 100);
                $t->unsignedBigInteger('company_id')->nullable();
                $t->string('token_hash', 64)->unique();
                $t->unsignedBigInteger('acknowledged')->default(0);
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }
        if (! $schema->hasTable('sync_conflicts')) {
            $schema->create('sync_conflicts', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('node_id');
                $t->unsignedBigInteger('seq');
                $t->string('table_name', 100);
                $t->unsignedBigInteger('pk');
                $t->char('op', 1);
                $t->longText('payload')->nullable();
                $t->string('reason', 60);
                $t->timestamp('created_at')->useCurrent();
                $t->timestamp('resolved_at')->nullable();
            });
        }
    }

    public static function drop(Connection $db): void
    {
        foreach (['sync_conflicts', 'sync_nodes', 'sync_cursors', 'sync_applied', 'sync_outbox', 'sync_state'] as $table) {
            $db->getSchemaBuilder()->dropIfExists($table);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Sync\SyncEngine;
use App\Services\Sync\SyncTriggers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Prototype: makes this server a sync node: its id, the sync tables, and the change-capture triggers on every table that travels. */
class SyncJoin extends Command
{
    protected $signature = 'sync:join {node : this server\'s node number (1 = head office)} {--remove : remove the triggers instead}';

    protected $description = 'Make this server a synchronisation node (prototype: nothing is sent anywhere yet)';

    public function handle(): int
    {
        $db = DB::connection();
        $tables = array_values(array_filter(array_merge(config('sync.master', []), config('sync.owned', [])), fn ($t) => Schema::hasTable($t)));

        if ($this->option('remove')) {
            SyncTriggers::remove($db, $tables);
            $this->info('Triggers removed from '.count($tables).' tables.');

            return self::SUCCESS;
        }

        $node = (int) $this->argument('node');
        if ($node < 1 || $node > 99) {
            $this->error('The node number is 1 to 99.');

            return self::FAILURE;
        }
        (new SyncEngine(config('database.default')))->join($node);
        $skipped = SyncTriggers::install($db, $tables);
        $this->info("Node {$node}: change capture on ".(count($tables) - count($skipped)).' tables.');
        if ($skipped) {
            $this->warn('No capture yet (no single id key): '.implode(', ', $skipped));
        }

        // Ids of two nodes must never meet: every node steps by 100 from its own number (MariaDB / MySQL).
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $vars = collect($db->select("SHOW VARIABLES WHERE Variable_name IN ('auto_increment_increment', 'auto_increment_offset')"))->pluck('Value', 'Variable_name');
            if ((int) ($vars['auto_increment_increment'] ?? 1) !== 100 || (int) ($vars['auto_increment_offset'] ?? 1) !== $node) {
                $this->warn("Set auto_increment_increment=100 and auto_increment_offset={$node} in the database server's settings, then restart it.");
            }
        }

        return self::SUCCESS;
    }
}

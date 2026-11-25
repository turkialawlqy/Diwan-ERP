<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Keeps the change feed from growing for ever: drops what every peer already holds. */
class SyncPrune extends Command
{
    protected $signature = 'sync:prune {--days=14 : Keep at least this many days}';

    protected $description = 'Delete change-feed rows every connected peer has already received';

    public function handle(): int
    {
        $db = DB::connection();
        $role = config('sync-node.role');
        if (! in_array($role, ['hub', 'branch'], true)) {
            return self::SUCCESS;
        }
        // Hub: up to what the slowest branch acknowledged. Branch: up to what it pushed.
        $upTo = $role === 'hub'
            ? (int) ($db->table('sync_nodes')->min('acknowledged') ?? 0)
            : (int) ($db->table('sync_cursors')->where('peer', 'hub')->value('pushed') ?? 0);
        $n = $db->table('sync_outbox')->where('id', '<=', $upTo)->where('created_at', '<', now()->subDays((int) $this->option('days')))->delete();
        $this->components->info("Pruned {$n} rows.");

        return self::SUCCESS;
    }
}

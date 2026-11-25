<?php

namespace App\Console\Commands;

use App\Services\Sync\HttpSyncTransport;
use App\Services\Sync\SyncClient;
use Illuminate\Console\Command;

/** One sync round with the head office. Run every minute by the scheduler on a branch; a dropped link just ends the round. */
class SyncRun extends Command
{
    protected $signature = 'sync:run';

    protected $description = 'Send this branch\'s changes to the head office and receive the head office\'s (branch servers only)';

    public function handle(): int
    {
        if (config('sync-node.role') !== 'branch') {
            $this->components->warn('This server is not a branch (SYNC_ROLE=branch).');

            return self::SUCCESS;
        }

        try {
            $r = (new SyncClient(config('database.default'), new HttpSyncTransport((string) config('sync-node.hub_url'), (string) config('sync-node.token')), (int) config('sync-node.batch', 200)))->run();
        } catch (\Throwable $e) {
            // Offline is the normal case, not an error: the cursors stay where they were and the next round resumes.
            $this->components->warn('Sync did not complete: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->components->info("Sent {$r['pushed']}, received {$r['pulled']} (applied {$r['applied']}, conflicts {$r['conflicts']}).");

        return self::SUCCESS;
    }
}

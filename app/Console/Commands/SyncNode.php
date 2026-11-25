<?php

namespace App\Console\Commands;

use App\Services\Sync\SyncHub;
use Illuminate\Console\Command;

/** Head office: lets a branch server connect and prints its token once. */
class SyncNode extends Command
{
    protected $signature = 'sync:node {node : Branch node number (2, 3, ...)} {name : Branch name} {--company= : Restrict the node to one company id}';

    protected $description = 'Register a branch server with the head office and print its sync token';

    public function handle(): int
    {
        if (config('sync-node.role') !== 'hub') {
            $this->components->error('Run this on the head office (SYNC_ROLE=hub).');

            return self::FAILURE;
        }
        $node = (int) $this->argument('node');
        if ($node < 2) {
            $this->components->error('Node 1 is the head office; branches start at 2.');

            return self::FAILURE;
        }
        $token = (new SyncHub(config('database.default')))->register($node, (string) $this->argument('name'), $this->option('company') ? (int) $this->option('company') : null);
        $this->components->info('Shown once; set these on the branch server:');
        $this->line("SYNC_ROLE=branch\nSYNC_HUB_URL=https://<head-office-address>\nSYNC_TOKEN={$token}");

        return self::SUCCESS;
    }
}

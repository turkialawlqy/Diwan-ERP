<?php

namespace App\Services\Platform;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What an uptime monitor should page on: the database, a stopped queue
 * worker, a stopped scheduler and a stale backup.
 */
class HealthCheck
{
    /** @return array{ok: bool, checks: array<string, array{ok: bool, detail: string}>} */
    public function run(): array
    {
        $checks = ['database' => $this->database(), 'queue' => $this->queue()];
        if (config('health.check_operations')) {
            $checks['scheduler'] = $this->scheduler();
            $checks['backup'] = $this->backup();
        }

        return ['ok' => collect($checks)->every(fn ($c) => $c['ok']), 'checks' => $checks];
    }

    protected function database(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true, 'detail' => 'connected'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'unreachable'];
        }
    }

    protected function queue(): array
    {
        $limit = (int) config('health.queue_max_minutes', 15);
        try {
            if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
                $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
                $waiting = $oldest ? (int) floor((now()->timestamp - (int) $oldest) / 60) : 0;

                return ['ok' => $waiting <= $limit, 'detail' => $oldest ? "oldest job waiting {$waiting} min" : 'empty'];
            }
            if (config('queue.default') === 'redis') {
                $size = collect(['zatca', 'default', 'webhooks'])->sum(fn ($q) => Queue::size($q));

                return ['ok' => $size < 1000, 'detail' => "{$size} jobs queued"];
            }

            return ['ok' => true, 'detail' => config('queue.default')];
        } catch (Throwable) {
            return ['ok' => false, 'detail' => 'queue unreachable'];
        }
    }

    protected function scheduler(): array
    {
        $beat = (int) Cache::get('scheduler:heartbeat', 0);
        $minutes = $beat ? (int) floor((now()->timestamp - $beat) / 60) : null;

        return ['ok' => $minutes !== null && $minutes <= (int) config('health.scheduler_max_minutes', 5),
            'detail' => $minutes === null ? 'no heartbeat (scheduler not running)' : "last run {$minutes} min ago"];
    }

    protected function backup(): array
    {
        $last = BackupRun::where('type', 'backup')->where('status', 'ok')->latest('id')->first();
        $hours = $last ? (int) floor($last->created_at->diffInMinutes(now()) / 60) : null;

        return ['ok' => $hours !== null && $hours <= (int) config('health.backup_max_hours', 26),
            'detail' => $hours === null ? 'no successful backup' : "last backup {$hours} h ago"];
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SyncScheduleTest extends TestCase
{
    protected function commands(string $role): array
    {
        config(['sync-node.role' => $role]);
        $this->app->singleton(Schedule::class, fn ($app) => new Schedule(config('app.timezone')));
        \Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        return array_map(fn ($event) => (string) $event->command, app(Schedule::class)->events());
    }

    public function test_a_branch_syncs_every_minute_and_the_head_office_rebuilds_its_totals_nightly(): void
    {
        $branch = implode("\n", $this->commands('branch'));
        $this->assertStringContainsString('sync:run', $branch);
        $this->assertStringNotContainsString('rebuild-balances', $branch);

        $hub = implode("\n", $this->commands('hub'));
        $this->assertStringContainsString('sync:prune', $hub);
        $this->assertStringContainsString('rebuild-balances', $hub);
        $this->assertStringContainsString('reconcile-parties --fix', $hub);
        $this->assertStringNotContainsString('sync:run', $hub);
    }

    public function test_a_normal_server_schedules_nothing_for_sync(): void
    {
        $this->assertStringNotContainsString('sync:', implode("\n", $this->commands('off')));
    }
}

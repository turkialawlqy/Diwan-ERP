<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Offline branches, phase 0: every table says who writes it (config/sync.php), so a new table cannot skip that decision. */
class SyncClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_is_classified_exactly_once_and_nothing_stale_is_listed(): void
    {
        $classes = config('sync');
        $this->assertSame(['master', 'owned', 'derived', 'local'], array_keys($classes));

        $seen = [];
        foreach ($classes as $class => $tables) {
            foreach ($tables as $table) {
                $this->assertArrayNotHasKey($table, $seen, $table.' is in two classes: '.($seen[$table] ?? '').' and '.$class);
                $seen[$table] = $class;
            }
        }

        $actual = collect(Schema::getTables())->pluck('name')->reject(fn ($t) => str_starts_with($t, 'sqlite_') || str_starts_with($t, 'sync_'))->all();
        $this->assertSame([], array_values(array_diff($actual, array_keys($seen))), 'Add the new table to config/sync.php (master, owned, derived or local).');
        $this->assertSame([], array_values(array_diff(array_keys($seen), $actual)), 'These tables no longer exist: remove them from config/sync.php.');
    }

    public function test_the_tables_that_must_never_leave_their_server_stay_local(): void
    {
        foreach (['zatca_devices', 'document_sequences', 'sessions', 'personal_access_tokens', 'backup_runs'] as $table) {
            $this->assertContains($table, config('sync.local'), "{$table} holds secrets or per-server state");
        }
        foreach (['account_balances', 'branch_account_balances', 'cash_flow_balances'] as $table) {
            $this->assertContains($table, config('sync.derived'), "{$table} is rebuilt after a merge");
        }
    }
}

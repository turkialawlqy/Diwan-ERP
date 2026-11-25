<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Sync\SyncEngine;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncStatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_sees_the_sync_state_and_waiting_conflicts_only_on_a_sync_node(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        app(TenantManager::class)->setTenant($company);
        $owner = $this->userWithRole($company);
        $this->actingAs($owner);

        // A normal server has no sync page.
        $this->get(route('settings.sync'))->assertNotFound();

        (new SyncEngine(config('database.default'), 'hub'))->join(1);
        config(['sync-node.role' => 'hub']);
        DB::table('sync_conflicts')->insert(['node_id' => 2, 'seq' => 5, 'table_name' => 'products', 'pk' => 9, 'op' => 'I', 'reason' => 'id_collision']);
        DB::table('sync_conflicts')->insert(['node_id' => 2, 'seq' => 6, 'table_name' => 'products', 'pk' => 10, 'op' => 'I', 'reason' => 'id_collision', 'resolved_at' => now()]);

        $this->get(route('settings.sync'))->assertOk()->assertInertia(fn ($p) => $p->component('Settings/Sync')->where('role', 'hub')->where('node', 1)->has('conflicts', 1));

        app(TenantManager::class)->forget();
    }
}

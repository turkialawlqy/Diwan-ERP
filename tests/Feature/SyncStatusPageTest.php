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

    public function test_the_owner_can_apply_or_reject_a_held_change_and_a_non_owner_cannot(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        app(TenantManager::class)->setTenant($company);
        $owner = $this->userWithRole($company);
        $this->actingAs($owner);
        (new SyncEngine(config('database.default'), 'hub'))->join(1);
        config(['sync-node.role' => 'hub']);

        $row = ['company_id' => $company->id, 'name' => 'اسم من الفرع', 'customer_type' => 'commercial', 'status' => 'active', 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
        $held = fn (int $seq, array $payload, string $reason = 'id_collision') => DB::table('sync_conflicts')->insertGetId(['node_id' => 2, 'seq' => $seq, 'table_name' => 'customers', 'pk' => $payload['id'], 'op' => 'I', 'payload' => json_encode($payload), 'reason' => $reason]);
        DB::table('customers')->insert(['id' => 500] + $row);

        // Applying a collision writes it over the row it collided with and passes it on to the other branches.
        $a = $held(1, ['id' => 500] + $row, 'id_collision');
        $this->post(route('settings.sync.resolve', [$a, 'accept']))->assertSessionHas('success');
        $this->assertNotNull(DB::table('sync_conflicts')->where('id', $a)->value('resolved_at'));
        $this->assertSame(1, DB::table('sync_outbox')->where('table_name', 'customers')->where('op', 'I')->where('node_id', 2)->count());

        // Rejecting closes it and changes nothing; a second decision on the same change finds nothing to do.
        $b = $held(2, ['id' => 501] + $row);
        $this->post(route('settings.sync.resolve', [$b, 'reject']))->assertSessionHas('success');
        $this->assertSame(0, DB::table('customers')->where('id', 501)->count());
        $this->post(route('settings.sync.resolve', [$b, 'accept']))->assertSessionHas('error');
        $this->get(route('settings.sync'))->assertInertia(fn ($p) => $p->has('conflicts', 0));

        // Anyone but the owner, and any other action, gets a 404.
        $this->post(route('settings.sync.resolve', [$b, 'delete']))->assertNotFound();
        $this->actingAs($this->userWithRole($company, 'sales'));
        $this->post(route('settings.sync.resolve', [$held(3, ['id' => 502] + $row), 'accept']))->assertNotFound();

        app(TenantManager::class)->forget();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Sync\SyncEngine;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The prototype's change capture on the real schema: every table that travels gets its triggers, and a normal save is captured. */
class SyncJoinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sync-node.settle' => 0]);
        $this->useSqliteHub();
    }

    protected string $originalConnection;

    protected function tearDown(): void
    {
        // The transaction RefreshDatabase opened is on the original connection: it must be the default again to be rolled back.
        config(['database.default' => $this->originalConnection]);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    /**
     * The head office of these tests is its own in-memory SQLite database, made the default connection for the test. Creating the
     * change-capture triggers and sync tables is DDL: on a shared MySQL test database it would commit the test transaction and leave
     * triggers behind for every later test. (The MySQL form of the triggers is therefore not exercised by this class.)
     */
    protected function useSqliteHub(): void
    {
        $this->originalConnection = config('database.default');
        config(['database.connections.sync_hub' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sync_hub');
        $this->artisan('migrate', ['--database' => 'sync_hub', '--force' => true])->assertSuccessful();
        DB::connection('sync_hub')->statement('PRAGMA foreign_keys = ON');
        config(['database.default' => 'sync_hub']);
        DB::setDefaultConnection('sync_hub');
    }

    /** @return list<string> */
    protected function triggerNames(): array
    {
        return collect(DB::select("select name from sqlite_master where type = 'trigger' and name like 'sync_%'"))->pluck('name')->all();
    }

    public function test_joining_installs_capture_on_every_replicated_table_and_a_real_save_is_captured(): void
    {
        $this->artisan('sync:join', ['node' => 3])->assertSuccessful();

        $travels = array_values(array_filter(array_merge(config('sync.master'), config('sync.owned')), fn ($t) => Schema::hasTable($t)));
        $this->assertGreaterThan(190, count($travels));
        $triggers = $this->triggerNames();
        $this->assertCount(count($travels) * 3, $triggers);

        $company = Company::factory()->create();
        app(TenantManager::class)->setTenant($company);
        $customer = Customer::create(['name' => 'عميل المزامنة', 'customer_type' => 'commercial', 'status' => 'active']);
        Customer::whereKey($customer->id)->update(['name' => 'اسم جديد']);          // an update
        DB::table('customers')->where('id', $customer->id)->delete();                // raw SQL, no model events

        $feed = DB::table('sync_outbox')->where('table_name', 'customers')->orderBy('id')->get();
        $this->assertSame(['I', 'U', 'D'], $feed->pluck('op')->all());
        $this->assertSame(3, (int) $feed[0]->node_id);
        $this->assertSame('عميل المزامنة', json_decode($feed[0]->payload, true)['name']);
        $this->assertSame('اسم جديد', json_decode($feed[1]->payload, true)['name']);
        app(TenantManager::class)->forget();

        $this->artisan('sync:join', ['node' => 3, '--remove' => true])->assertSuccessful();
        $this->assertSame([], $this->triggerNames());
    }

    public function test_real_rows_travel_to_a_second_database_with_the_real_schema_unchanged(): void
    {
        config(['database.connections.sync_b' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sync_b');
        $this->artisan('migrate', ['--database' => 'sync_b', '--force' => true])->assertSuccessful();
        $branch = new SyncEngine('sync_b', 'branch');
        $branch->join(2);
        $this->artisan('sync:join', ['node' => 1])->assertSuccessful();
        $hub = new SyncEngine(config('database.default'), 'hub');

        $company = Company::factory()->create();
        app(TenantManager::class)->setTenant($company);
        $product = Product::create(['name_ar' => 'صنف', 'sku' => 'SY1', 'selling_price' => 12.345, 'track_inventory' => true, 'is_active' => true]);
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active', 'credit_limit' => 5000.5]);
        app(TenantManager::class)->forget();

        $result = $branch->apply($hub->pending());
        $this->assertSame(0, $result['conflicts']);
        foreach ([['products', $product->id], ['customers', $customer->id]] as [$table, $id]) {
            $here = (array) DB::table($table)->where('id', $id)->first();
            $there = (array) DB::connection('sync_b')->table($table)->where('id', $id)->first();
            $this->assertNotEmpty($there, "{$table} row did not arrive");
            $this->assertEquals($here, $there, "{$table} row differs after the trip");
        }
    }

    public function test_link_tables_without_an_id_travel_as_whole_rows(): void
    {
        config(['database.connections.sync_b' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sync_b');
        $this->artisan('migrate', ['--database' => 'sync_b', '--force' => true])->assertSuccessful();
        $branch = new SyncEngine('sync_b', 'branch');
        $branch->join(2);
        $this->artisan('sync:join', ['node' => 1])->assertSuccessful();
        $hub = new SyncEngine(config('database.default'), 'hub');

        $company = Company::factory()->create();
        $role = DB::table('roles')->insertGetId(['name' => 'r', 'guard_name' => 'web', 'team_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);
        $p7 = DB::table('permissions')->insertGetId(['name' => 'p7', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $p8 = DB::table('permissions')->insertGetId(['name' => 'p8', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_has_permissions')->insert([['permission_id' => $p7, 'role_id' => $role], ['permission_id' => $p8, 'role_id' => $role]]);
        $feed = $hub->pending();
        $this->assertSame(['I', 'I'], array_column(array_filter($feed, fn ($c) => $c['table'] === 'role_has_permissions'), 'op'));
        $this->assertSame(0, $branch->apply($feed)['conflicts']);
        $this->assertSame(2, DB::connection('sync_b')->table('role_has_permissions')->count());

        // Re-sending is harmless, and a delete removes exactly that row.
        $branch->apply($feed);
        $this->assertSame(2, DB::connection('sync_b')->table('role_has_permissions')->count());
        $seen = count($feed);
        DB::table('role_has_permissions')->where('permission_id', $p7)->delete();
        $branch->apply(array_values(array_filter($hub->pending($seen ? max(array_column($feed, 'feed')) : 0), fn ($c) => $c['table'] === 'role_has_permissions')));
        $this->assertSame([$p8], DB::connection('sync_b')->table('role_has_permissions')->pluck('permission_id')->all());
    }
}

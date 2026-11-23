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

    public function test_joining_installs_capture_on_every_replicated_table_and_a_real_save_is_captured(): void
    {
        $this->artisan('sync:join', ['node' => 3])->assertSuccessful();

        $travels = array_values(array_filter(array_merge(config('sync.master'), config('sync.owned')), fn ($t) => Schema::hasColumn($t, 'id')));
        $this->assertGreaterThan(190, count($travels));
        $triggers = collect(DB::select("select name from sqlite_master where type = 'trigger' and name like 'sync_%'"))->pluck('name')->all();
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
        $this->assertSame([], DB::select("select name from sqlite_master where type = 'trigger' and name like 'sync_%'"));
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
}

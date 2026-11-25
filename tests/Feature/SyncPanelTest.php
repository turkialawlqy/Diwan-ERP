<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Sync\SyncHub;
use App\Services\Sync\SyncSettings;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Linking the head office and branches from the Settings page: no terminal, no file editing. */
class SyncPanelTest extends TestCase
{
    use RefreshDatabase;

    protected string $original;

    protected string $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // The page creates sync tables and capture triggers (DDL): on its own in-memory SQLite database, never on a shared MySQL one.
        $this->original = config('database.default');
        config(['database.connections.sync_panel' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sync_panel');
        $this->artisan('migrate', ['--database' => 'sync_panel', '--force' => true])->assertSuccessful();
        config(['database.default' => 'sync_panel']);
        DB::setDefaultConnection('sync_panel');
        $this->settings = sys_get_temp_dir().'/diwan-sync-settings-'.uniqid().'.json';
        putenv('SYNC_SETTINGS_FILE='.$this->settings);
        config(['sync-node.settle' => 0, 'app.local_install' => true, 'sync-node.role' => 'off']);

        $company = Company::factory()->create();
        app(TenantManager::class)->setTenant($company);
        $this->owner = $this->userWithRole($company);
        $this->actingAs($this->owner);
    }

    protected User $owner;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::delete($this->settings);
        putenv('SYNC_SETTINGS_FILE');
        config(['database.default' => $this->original]);
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_only_the_owner_of_a_local_install_gets_the_page(): void
    {
        $this->get(route('settings.sync'))->assertOk()->assertInertia(fn ($p) => $p->component('Settings/Sync')->where('role', 'off'));

        config(['app.local_install' => false]);
        $this->get(route('settings.sync'))->assertNotFound();
        $this->post(route('settings.sync.hub'))->assertNotFound();

        config(['app.local_install' => true]);
        $this->actingAs($this->userWithRole($this->owner->company, 'sales'));
        $this->get(route('settings.sync'))->assertNotFound();
        $this->post(route('settings.sync.hub'))->assertNotFound();
    }

    public function test_one_click_makes_this_server_the_head_office_and_branches_get_a_connection_code(): void
    {
        $this->post(route('settings.sync.hub'))->assertSessionHas('success');
        $this->assertSame('hub', SyncSettings::load()['role']);
        $this->assertSame('hub', config('sync-node.role'));
        $this->assertSame(1, (int) DB::table('sync_state')->value('node_id'));

        $this->post(route('settings.sync.branches.add'), ['name' => 'فرع جدة', 'hub_url' => 'https://hq.example.com/'])
            ->assertSessionHas('sync_code');
        $code = session('sync_code');
        $parsed = SyncSettings::parseCode($code);
        $this->assertSame(['hub_url' => 'https://hq.example.com', 'node' => 2], ['hub_url' => $parsed['hub_url'], 'node' => $parsed['node']]);
        $this->assertSame(2, (int) DB::table('sync_nodes')->where('token_hash', hash('sha256', $parsed['token']))->value('node_id'));

        $this->post(route('settings.sync.branches.add'), ['name' => 'فرع الدمام', 'hub_url' => 'https://hq.example.com']);
        $this->assertSame(3, SyncSettings::parseCode(session('sync_code'))['node']);
        $this->post(route('settings.sync.branches.add'), ['name' => 'x', 'hub_url' => 'not a url'])->assertSessionHasErrors('hub_url');

        // The page shows the branches, and cutting one off stops its token.
        $this->get(route('settings.sync'))->assertInertia(fn ($p) => $p->where('role', 'hub')->has('nodes', 2));
        $this->delete(route('settings.sync.branches.remove', 2))->assertSessionHas('success');
        $this->assertNull((new SyncHub(config('database.default')))->authenticate($parsed['token']));
    }

    public function test_a_bad_or_mismatched_code_changes_nothing(): void
    {
        $this->post(route('settings.sync.connect'), ['code' => 'garbage', 'confirm' => true])->assertSessionHasErrors('code');
        $good = SyncSettings::connectionCode('https://hq.example.com', 2, 'tok');
        $this->post(route('settings.sync.connect'), ['code' => $good])->assertSessionHasErrors('confirm');

        Http::fake(['*' => Http::response('no', 401)]);
        $this->post(route('settings.sync.connect'), ['code' => $good, 'confirm' => true])->assertSessionHasErrors('code');
        Http::fake(['*' => Http::response(['ok' => false, 'version' => '0.0.1'])]);
        $this->post(route('settings.sync.connect'), ['code' => $good, 'confirm' => true])->assertSessionHasErrors('code');

        $this->assertSame([], SyncSettings::load());
        $this->assertSame('off', config('sync-node.role'));
    }

    public function test_connecting_a_branch_copies_the_head_office_data_and_signs_everyone_out(): void
    {
        $version = trim(file_get_contents(base_path('VERSION')));
        Http::fake([
            '*/handshake' => Http::response(['ok' => true, 'version' => $version, 'node_id' => 2]),
            '*/snapshot-info' => Http::response(['tables' => ['units'], 'ceiling' => 7]),
            '*/snapshot-page' => Http::response(['rows' => [['id' => 5, 'company_id' => $this->owner->company_id, 'name_ar' => 'وحدة المكتب', 'name_en' => null, 'code' => null, 'created_at' => null, 'updated_at' => null]], 'next' => 5, 'more' => false]),
        ]);
        DB::table('units')->insert(['id' => 99, 'company_id' => $this->owner->company_id, 'name_ar' => 'محلية']);

        $code = SyncSettings::connectionCode('https://hq.example.com', 2, 'tok');
        $this->post(route('settings.sync.connect'), ['code' => $code, 'confirm' => true])->assertRedirect(route('login'));

        $this->assertSame(['role' => 'branch', 'node' => 2, 'hub_url' => 'https://hq.example.com', 'token' => 'tok'], SyncSettings::load());
        $this->assertSame([5], DB::table('units')->pluck('id')->all());   // the local row is gone, the head office's is here
        $this->assertSame(7, (int) DB::table('sync_cursors')->where('peer', 'hub')->value('pulled'));
        $this->assertSame(1, (int) DB::table('sync_cursors')->where('peer', 'snapshot')->value('pulled'));
        $this->assertGuest();
    }

    public function test_unlinking_stops_the_capture_and_keeps_the_data(): void
    {
        $this->post(route('settings.sync.hub'));
        $this->assertGreaterThan(0, count(DB::select("select name from sqlite_master where type = 'trigger' and name like 'sync_%'")));

        $this->post(route('settings.sync.disconnect'))->assertSessionHas('success');
        $this->assertSame('off', SyncSettings::load()['role']);
        $this->assertSame([], DB::select("select name from sqlite_master where type = 'trigger' and name like 'sync_%'"));
    }

    public function test_the_connection_code_round_trips_and_rejects_tampering(): void
    {
        $code = SyncSettings::connectionCode('https://a.example.com', 9, 'abc123');
        $this->assertSame(['hub_url' => 'https://a.example.com', 'node' => 9, 'token' => 'abc123'], SyncSettings::parseCode($code));
        $this->assertNull(SyncSettings::parseCode(substr($code, 0, -4)));
        $this->assertNull(SyncSettings::parseCode('DIWAN1.'.base64_encode('{"u":"javascript:x","n":2,"t":"t"}')));
        $this->assertNull(SyncSettings::parseCode(SyncSettings::connectionCode('https://a.example.com', 1, 'abc')));   // node 1 is the head office
    }
}

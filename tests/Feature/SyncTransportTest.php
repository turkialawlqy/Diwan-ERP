<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Sync\SyncClient;
use App\Services\Sync\SyncEngine;
use App\Services\Sync\SyncHub;
use App\Services\Sync\SyncTransport;
use App\Services\Sync\SyncTriggers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Head office + two branches (three databases), talking through the hub's real classes: push, relay, resume, pruning, refusals. */
class SyncTransportTest extends TestCase
{
    use RefreshDatabase;

    protected SyncHub $hub;

    protected array $tokens = [];

    public bool $down = false;

    public string $currentServer = 'b';

    protected string $disksRoot;

    protected int $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sync-node.settle' => 0]);
        $this->useSqliteHub();
        $this->disksRoot = sys_get_temp_dir().'/diwan-sync-files-'.uniqid();
        foreach ([2 => 'sync_b', 3 => 'sync_c'] as $node => $name) {
            config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
            DB::purge($name);
            $this->artisan('migrate', ['--database' => $name, '--force' => true])->assertSuccessful();
            DB::connection($name)->statement('PRAGMA foreign_keys = OFF');
            (new SyncEngine($name, 'branch'))->join($node);
            SyncTriggers::install(DB::connection($name), ['products', 'attachments']);
        }
        (new SyncEngine(config('database.default'), 'hub'))->join(1);
        SyncTriggers::install(DB::connection(), ['products', 'attachments']);
        $this->company = Company::factory()->create()->id;   // the hub keeps its foreign keys: the rows need their company
        $this->hub = new SyncHub(config('database.default'));
        $this->tokens = [2 => $this->hub->register(2, 'Branch B'), 3 => $this->hub->register(3, 'Branch C')];
    }

    protected string $originalConnection;

    protected function tearDown(): void
    {
        File::deleteDirectory($this->disksRoot);
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
    /** Each server has its own storage folder; the "local" disk points at the one whose turn it is. */
    public function serverDisk(string $server): void
    {
        config(['filesystems.disks.local.root' => "{$this->disksRoot}/{$server}"]);
        Storage::purge('local');
    }

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

    protected function client(int $node, string $connection, ?string $token = null, int $batch = 200): SyncClient
    {
        $token ??= $this->tokens[$node];
        $transport = new class($this->hub, $token, $this) implements SyncTransport
        {
            public function __construct(private SyncHub $hub, private string $token, private SyncTransportTest $test) {}

            public function post(string $path, array $payload): array
            {
                if ($this->test->down) {
                    throw new \RuntimeException('offline');
                }
                $this->test->serverDisk('hub');
                $node = $this->hub->authenticate($this->token);
                if (! $node) {
                    throw new \RuntimeException('401');
                }

                try {
                    return match ($path) {
                        'handshake' => $this->hub->handshake($node, $payload),
                        'push' => $this->hub->receive($node, $payload['changes']),
                        'pull' => $this->hub->feed($node, $payload['after'], $payload['limit']),
                        'files-missing' => ['missing' => $this->hub->filesMissing($node, $payload['items'])],
                        'files-put' => ['stored' => $this->hub->filePut($node, $payload['table'], $payload['path'], $payload['content'])],
                        'files-get' => ['content' => $this->hub->fileGet($node, $payload['table'], $payload['path'])],
                    };
                } finally {
                    $this->test->serverDisk($this->test->currentServer);
                }
            }
        };

        return new SyncClient($connection, $transport, $batch);
    }

    protected function product(string $connection, string $sku): void
    {
        DB::connection($connection)->table('products')->insert(['company_id' => $this->company, 'name_ar' => $sku, 'sku' => $sku, 'selling_price' => 5, 'is_active' => 1, 'track_inventory' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_branch_change_reaches_the_hub_and_the_other_branch_but_never_returns_to_its_sender(): void
    {
        $this->product('sync_b', 'FROM-B');
        $b = $this->client(2, 'sync_b');
        $c = $this->client(3, 'sync_c');

        $this->assertSame(1, $b->run()['pushed']);
        $this->assertSame(1, DB::table('products')->where('sku', 'FROM-B')->count());
        $this->assertSame(1, $c->run()['applied']);
        $this->assertSame(1, DB::connection('sync_c')->table('products')->where('sku', 'FROM-B')->count());

        // Nothing bounces back: B's next round has nothing to apply, and C (which only received) has nothing to push.
        $again = $b->run();
        $this->assertSame(0, $again['applied']);
        $this->assertSame(0, $again['pushed']);
        $this->assertSame(0, $c->run()['pushed']);
        $this->assertSame(1, DB::connection('sync_b')->table('products')->count());
    }

    public function test_a_dropped_link_resumes_where_it_stopped_without_loss_or_duplicates(): void
    {
        foreach (['P1', 'P2', 'P3', 'P4', 'P5'] as $sku) {
            $this->product('sync_b', $sku);
        }
        $this->down = true;
        try {
            $this->client(2, 'sync_b', null, 2)->run();
            $this->fail('offline must throw');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('products')->count());

        $this->down = false;
        $result = $this->client(2, 'sync_b', null, 2)->run();   // small batches: several round trips
        $this->assertSame(5, $result['pushed']);
        $this->assertSame(5, DB::table('products')->count());

        $this->client(2, 'sync_b')->run();   // a second full round changes nothing
        $this->assertSame(5, DB::table('products')->count());
        $this->assertSame(5, DB::connection('sync_b')->table('products')->count());
    }

    public function test_a_cursor_moves_only_after_the_other_side_confirmed(): void
    {
        $this->product('sync_b', 'A');
        $this->down = true;
        try {
            $this->client(2, 'sync_b')->run();
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, (int) DB::connection('sync_b')->table('sync_cursors')->where('peer', 'hub')->value('pushed'));
    }

    public function test_the_hub_refuses_unknown_tokens_foreign_nodes_and_other_companies(): void
    {
        $this->assertNull($this->hub->authenticate('nope'));
        $this->assertNull($this->hub->authenticate(null));

        $node = $this->hub->authenticate($this->tokens[2]);
        $row = fn (int $nodeId, int $company) => ['node_id' => $nodeId, 'seq' => 99, 'table' => 'products', 'pk' => 7, 'op' => 'I', 'payload' => ['id' => 7, 'company_id' => $company, 'name_ar' => 'x', 'sku' => 'X', 'selling_price' => 1, 'is_active' => 1, 'track_inventory' => 1]];

        // A branch cannot write as another node.
        $this->assertSame(1, $this->hub->receive($node, [$row(3, $this->company)])['refused']);

        // A node tied to one company cannot write another's data.
        $token = $this->hub->register(4, 'Tied', $this->company);
        $tied = $this->hub->authenticate($token);
        $this->assertSame(1, $this->hub->receive($tied, [$row(4, $this->company + 1)])['refused']);
        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_a_different_release_is_refused_at_the_handshake(): void
    {
        $node = $this->hub->authenticate($this->tokens[2]);
        $this->assertTrue($this->hub->handshake($node, ['version' => trim(file_get_contents(base_path('VERSION')))])['ok']);
        $this->assertFalse($this->hub->handshake($node, ['version' => '0.0.1'])['ok']);
    }

    public function test_pruning_drops_only_what_every_branch_already_holds(): void
    {
        $this->product('sync_b', 'OLD');
        $this->client(2, 'sync_b')->run();
        $this->client(3, 'sync_c')->run();   // C pulls the hub's feed...
        $this->client(3, 'sync_c')->run();   // ...and its next request tells the hub it holds it
        $this->client(2, 'sync_b')->run();
        $this->client(2, 'sync_b')->run();

        DB::table('sync_outbox')->update(['created_at' => now()->subDays(30)]);
        config(['sync-node.role' => 'hub']);
        $before = DB::table('sync_outbox')->count();
        $this->assertGreaterThan(0, $before);
        $this->artisan('sync:prune')->assertSuccessful();
        $this->assertLessThan($before, DB::table('sync_outbox')->count());

        // A branch that never connected holds nothing: the feed is kept for it.
        $this->hub->register(9, 'Silent');
        $this->product('sync_b', 'NEW');
        $this->client(2, 'sync_b')->run();
        DB::table('sync_outbox')->update(['created_at' => now()->subDays(30)]);
        $this->artisan('sync:prune')->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('sync_outbox')->count());
    }

    public function test_a_branch_entry_dated_in_a_closed_period_waits_for_a_person_with_its_lines(): void
    {
        $year = DB::table('fiscal_years')->insertGetId(['company_id' => $this->company, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['2026-01-01', '2026-01-31', 'closed'], ['2026-02-01', '2026-02-28', 'open']] as [$from, $to, $status]) {
            DB::table('fiscal_periods')->insert(['company_id' => $this->company, 'fiscal_year_id' => $year, 'name' => $from, 'start_date' => $from, 'end_date' => $to, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        $entry = fn (int $id, string $date) => ['node_id' => 2, 'seq' => $id, 'table' => 'journal_entries', 'pk' => $id, 'op' => 'I', 'payload' => ['id' => $id, 'company_id' => $this->company, 'date' => $date, 'entry_number' => "E{$id}", 'description' => 'x', 'status' => 'posted']];
        $line = fn (int $id, int $entryId) => ['node_id' => 2, 'seq' => 100 + $id, 'table' => 'journal_entry_lines', 'pk' => $id, 'op' => 'I', 'payload' => ['id' => $id, 'journal_entry_id' => $entryId, 'account_id' => 1, 'debit' => 5, 'credit' => 0]];

        $engine = new SyncEngine(config('database.default'), 'hub');
        $result = $engine->apply([$entry(1, '2026-01-15'), $line(1, 1), $entry(2, '2026-02-10')]);

        $this->assertSame(['applied' => 1, 'skipped' => 0, 'conflicts' => 2], $result);
        $this->assertSame([2], DB::table('journal_entries')->pluck('id')->all());
        $this->assertSame(0, DB::table('journal_entry_lines')->count());
        $this->assertSame(['closed_period', 'closed_period'], DB::table('sync_conflicts')->pluck('reason')->all());
    }

    public function test_balances_and_costs_each_server_keeps_for_itself_are_not_master_edits_and_never_overwritten(): void
    {
        $base = ['company_id' => $this->company, 'name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()];
        $id = DB::table('customers')->insertGetId($base + ['current_balance' => 100]);
        $hub = new SyncEngine(config('database.default'), 'hub');
        $row = fn (array $over) => ['node_id' => 2, 'seq' => random_int(1000, 9999), 'table' => 'customers', 'pk' => $id, 'op' => 'U', 'payload' => ['id' => $id] + $over + $base + ['current_balance' => 100]];

        // A branch sale moved only the balance: dropped quietly, not a conflict, not relayed.
        $this->assertSame(['applied' => 0, 'skipped' => 1, 'conflicts' => 0], $hub->apply([$row(['current_balance' => 340])]));
        $this->assertSame(100.0, (float) DB::table('customers')->where('id', $id)->value('current_balance'));
        $this->assertSame(0, DB::table('sync_outbox')->where('table_name', 'customers')->where('op', 'U')->count());

        // A branch that really edited the customer still waits for a person.
        $this->assertSame(1, $hub->apply([$row(['name' => 'اسم مغيّر', 'current_balance' => 340])])['conflicts']);

        // The head office's edit reaches a branch without overwriting the branch's own balance.
        DB::connection('sync_b')->table('customers')->insert(['id' => $id] + $base + ['current_balance' => 777]);
        $branch = new SyncEngine('sync_b', 'branch');
        $update = ['node_id' => 1, 'seq' => 5000, 'table' => 'customers', 'pk' => $id, 'op' => 'U', 'payload' => ['id' => $id] + ['name' => 'اسم المركز'] + $base + ['current_balance' => 100]];
        $this->assertSame(1, $branch->apply([$update])['applied']);
        $this->assertSame(['اسم المركز', 777.0], [DB::connection('sync_b')->table('customers')->where('id', $id)->value('name'), (float) DB::connection('sync_b')->table('customers')->where('id', $id)->value('current_balance')]);
    }

    public function test_uploaded_files_follow_their_rows_through_the_head_office_to_the_other_branch(): void
    {
        $row = ['company_id' => $this->company, 'attachable_type' => null, 'attachable_id' => null, 'path' => 'companies/1/docs/a.txt', 'original_name' => 'a.txt', 'mime' => 'text/plain', 'size' => 5, 'created_at' => now(), 'updated_at' => now()];
        $this->serverDisk('b');
        Storage::disk('local')->put('companies/1/docs/a.txt', 'hello');
        DB::connection('sync_b')->table('attachments')->insert($row);

        $this->currentServer = 'b';
        $sent = $this->client(2, 'sync_b')->run();
        $this->assertSame(1, $sent['files_sent']);
        $this->serverDisk('hub');
        $this->assertSame('hello', Storage::disk('local')->get('companies/1/docs/a.txt'));

        // The other branch gets the row first, then the file; a second round sends and fetches nothing more.
        $this->currentServer = 'c';
        $this->serverDisk('c');
        $got = $this->client(3, 'sync_c')->run();
        $this->assertSame(1, $got['files_fetched']);
        $this->assertSame('hello', Storage::disk('local')->get('companies/1/docs/a.txt'));
        $again = $this->client(3, 'sync_c')->run();
        $this->assertSame([0, 0], [$again['files_sent'], $again['files_fetched']]);
    }

    public function test_the_hub_serves_and_accepts_only_files_a_row_points_at_and_only_safe_paths(): void
    {
        $node = $this->hub->authenticate($this->tokens[2]);
        $this->serverDisk('hub');
        Storage::disk('local')->put('secret.txt', 'no');
        $this->assertNull($this->hub->fileGet($node, 'attachments', 'secret.txt'));                 // no row points at it
        $this->assertNull($this->hub->fileGet($node, 'attachments', '../.env'));                     // not a safe path
        $this->assertFalse($this->hub->filePut($node, 'attachments', 'companies/1/x.txt', base64_encode('x')));   // no row yet
        $this->assertNull($this->hub->fileGet($node, 'users', 'secret.txt'));                        // not a registered file column
    }
}

<?php

namespace Tests\Feature;

use App\Services\Sync\SyncEngine;
use App\Services\Sync\SyncTriggers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The MySQL / MariaDB form of the change-capture triggers, run for real. It needs a server where it may create a database of its own
 * and set log_bin_trust_function_creators, so it runs only where SYNC_MYSQL_ROOT_PASSWORD names a root password (phpunit.mysql.xml, the
 * CI MySQL job); everywhere else it says so. It never touches the application's own test database.
 */
class SyncMysqlTriggersTest extends TestCase
{
    protected const DATABASE = 'diwan_sync_triggers';

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('SYNC_MYSQL_ROOT_PASSWORD') || config('database.default') !== 'mysql') {
            $this->markTestSkipped('Needs a MySQL server and its root password (SYNC_MYSQL_ROOT_PASSWORD): runs in the MySQL CI job only.');
        }
        config(['sync-node.settle' => 0, 'sync.master' => [], 'sync.owned' => ['docs', 'doc_links'], 'sync.derived' => [], 'sync.local' => []]);

        $base = ['driver' => 'mysql', 'host' => config('database.connections.mysql.host'), 'port' => config('database.connections.mysql.port'),
            'username' => 'root', 'password' => env('SYNC_MYSQL_ROOT_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        config(['database.connections.sync_root' => $base + ['database' => null]]);
        DB::purge('sync_root');
        DB::connection('sync_root')->statement('DROP DATABASE IF EXISTS '.self::DATABASE);
        DB::connection('sync_root')->statement('CREATE DATABASE '.self::DATABASE.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        DB::connection('sync_root')->statement('SET GLOBAL log_bin_trust_function_creators = 1');
        config(['database.connections.sync_mysql' => $base + ['database' => self::DATABASE]]);
        DB::purge('sync_mysql');

        $schema = DB::connection('sync_mysql')->getSchemaBuilder();
        $schema->create('docs', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40);
            $t->decimal('total', 12, 2)->default(0);
            $t->string('note')->nullable();
        });
        $schema->create('doc_links', function (Blueprint $t) {
            $t->unsignedBigInteger('doc_id');
            $t->unsignedBigInteger('tag_id');
        });
        $this->hub = new SyncEngine('sync_mysql', 'hub');
        $this->hub->join(1);
        SyncTriggers::install(DB::connection('sync_mysql'), ['docs', 'doc_links']);
    }

    protected function tearDown(): void
    {
        if (config('database.connections.sync_root')) {
            try {
                DB::connection('sync_root')->statement('DROP DATABASE IF EXISTS '.self::DATABASE);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    protected SyncEngine $hub;

    protected function db()
    {
        return DB::connection('sync_mysql');
    }

    public function test_inserts_updates_and_deletes_are_captured_with_the_row_as_json(): void
    {
        $this->db()->table('docs')->insert(['id' => 101, 'number' => 'ف-1', 'total' => 12.5]);
        $this->db()->table('docs')->where('id', 101)->update(['note' => 'تعديل']);
        $this->db()->table('docs')->where('id', 101)->delete();

        $feed = $this->hub->pending();
        $this->assertSame(['I', 'U', 'D'], array_column($feed, 'op'));
        $this->assertSame([101, 101, 101], array_column($feed, 'pk'));
        $this->assertSame('ف-1', $feed[0]['payload']['number']);
        $this->assertEquals(12.5, $feed[0]['payload']['total']);
        $this->assertSame('تعديل', $feed[1]['payload']['note']);
        $this->assertNull($feed[2]['payload']);
        $this->assertSame([1, 1, 1], array_column($feed, 'node_id'));
    }

    public function test_a_link_table_row_is_captured_whole_and_an_update_is_a_delete_plus_an_insert(): void
    {
        $this->db()->table('doc_links')->insert(['doc_id' => 1, 'tag_id' => 7]);
        $this->db()->table('doc_links')->where('tag_id', 7)->update(['tag_id' => 8]);

        $feed = $this->hub->pending();
        $this->assertSame(['I', 'D', 'I'], array_column($feed, 'op'));
        $this->assertSame([0, 0, 0], array_column($feed, 'pk'));
        $this->assertEquals(7, $feed[1]['payload']['tag_id']);
        $this->assertEquals(8, $feed[2]['payload']['tag_id']);
    }

    public function test_changes_made_while_a_merge_is_applied_are_not_captured_and_the_merge_writes_through_mysql(): void
    {
        // A change from a branch arrives: it is written (upsert syntax of MySQL), relayed once, and never echoed as the head office's own.
        $change = ['node_id' => 2, 'seq' => 1, 'table' => 'docs', 'pk' => 202, 'op' => 'I', 'payload' => ['id' => 202, 'number' => 'BR-1', 'total' => 3, 'note' => null]];
        $this->assertSame(['applied' => 1, 'skipped' => 0, 'conflicts' => 0], $this->hub->apply([$change]));
        $this->assertSame('BR-1', $this->db()->table('docs')->where('id', 202)->value('number'));

        $feed = $this->hub->pending();
        $this->assertCount(1, $feed);
        $this->assertSame(2, $feed[0]['node_id']);   // relayed as the branch's change, not captured as a local one
        $this->assertSame(0, (int) $this->db()->table('sync_state')->value('applying'));

        // Sent again: still one row (the same change twice is applied once).
        $this->assertSame(1, $this->hub->apply([$change])['skipped']);
        $this->assertSame(1, $this->db()->table('docs')->count());

        // An update that arrives and a delete follow the same path.
        $this->hub->apply([['node_id' => 2, 'seq' => 2, 'table' => 'docs', 'pk' => 202, 'op' => 'U', 'payload' => ['id' => 202, 'number' => 'BR-1', 'total' => 9, 'note' => 'x']]]);
        $this->assertEquals(9, $this->db()->table('docs')->where('id', 202)->value('total'));
        $this->hub->apply([['node_id' => 2, 'seq' => 3, 'table' => 'docs', 'pk' => 202, 'op' => 'D', 'payload' => null]]);
        $this->assertSame(0, $this->db()->table('docs')->count());
    }

    public function test_the_settle_window_hides_fresh_rows_and_the_ceiling_follows_it(): void
    {
        $this->db()->table('docs')->insert(['id' => 303, 'number' => 'N', 'total' => 1]);
        config(['sync-node.settle' => 3600]);
        $this->assertSame([], $this->hub->pending());
        $this->assertSame(0, $this->hub->ceiling());

        config(['sync-node.settle' => 0]);
        $this->assertCount(1, $this->hub->pending());
        $this->assertGreaterThan(0, $this->hub->ceiling());
    }

    public function test_removing_the_triggers_stops_the_capture(): void
    {
        SyncTriggers::remove($this->db(), ['docs', 'doc_links']);
        $this->db()->table('docs')->insert(['id' => 404, 'number' => 'N', 'total' => 1]);
        $this->assertSame([], $this->hub->pending());
    }
}

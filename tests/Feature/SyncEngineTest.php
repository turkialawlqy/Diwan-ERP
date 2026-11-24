<?php

namespace Tests\Feature;

use App\Services\Sync\SyncEngine;
use App\Services\Sync\SyncTriggers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prototype of the offline-branch sync (plan 32, phase 0): two nodes, each with its own database, exchange changes
 * through the engine only. The tables here are test tables classified for the test (docs = owned, parties = master).
 */
class SyncEngineTest extends TestCase
{
    protected SyncEngine $hub;

    protected SyncEngine $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sync.master' => ['parties'], 'sync.owned' => ['docs'], 'sync.derived' => [], 'sync.local' => []]);
        foreach (['hub', 'branch'] as $name) {
            config(["database.connections.sync_{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
            DB::purge("sync_{$name}");
            $schema = DB::connection("sync_{$name}")->getSchemaBuilder();
            $schema->create('parties', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->timestamps();
            });
            $schema->create('docs', function (Blueprint $t) {
                $t->id();
                $t->string('number');
                $t->decimal('total', 12, 2)->default(0);
                $t->string('note')->nullable();
            });
        }
        $this->hub = new SyncEngine('sync_hub', 'hub');
        $this->branch = new SyncEngine('sync_branch', 'branch');
        $this->hub->join(1);
        $this->branch->join(2);
        SyncTriggers::install(DB::connection('sync_hub'), ['parties', 'docs']);
        SyncTriggers::install(DB::connection('sync_branch'), ['parties', 'docs']);
    }

    protected function doc(string $node, int $id, string $number = 'D', float $total = 10): void
    {
        DB::connection("sync_{$node}")->table('docs')->insert(['id' => $id, 'number' => $number, 'total' => $total]);
    }

    protected function ids(string $node, string $table = 'docs'): array
    {
        return DB::connection("sync_{$node}")->table($table)->orderBy('id')->pluck('id')->all();
    }

    public function test_a_branch_sale_reaches_the_head_office_once_even_if_sent_twice(): void
    {
        $this->doc('branch', 102, 'INV-B2-1', 115.50);
        DB::connection('sync_branch')->table('docs')->where('id', 102)->update(['note' => 'تعديل']);

        $feed = $this->branch->pending();
        $this->assertSame(['I', 'U'], array_column($feed, 'op'));
        $this->assertSame([2, 2], array_column($feed, 'node_id'));

        $this->assertSame(['applied' => 2, 'skipped' => 0, 'conflicts' => 0], $this->hub->apply($feed));
        $row = DB::connection('sync_hub')->table('docs')->first();
        $this->assertSame('INV-B2-1', $row->number);
        $this->assertSame('تعديل', $row->note);
        $this->assertSame(115.5, (float) $row->total);

        // The same delivery again (a retry after a dropped link) changes nothing.
        $this->assertSame(['applied' => 0, 'skipped' => 2, 'conflicts' => 0], $this->hub->apply($feed));
        $this->assertSame(1, DB::connection('sync_hub')->table('docs')->count());
    }

    public function test_a_merge_does_not_echo_back_out(): void
    {
        $this->doc('branch', 102);
        $this->hub->apply($this->branch->pending());

        // The head office relays what it accepted (for the other branches) but never back to the node that made it, and
        // a branch relays nothing: two nodes never ping-pong.
        $this->assertCount(1, $this->hub->pending());
        $this->assertSame([], $this->hub->pending(0, 500, 2));
        $this->assertSame(1, $this->branch->apply($this->hub->pending())['skipped']);
        $this->assertSame(1, DB::connection('sync_branch')->table('sync_outbox')->count());
    }

    public function test_a_delete_travels_and_an_update_before_its_insert_still_lands(): void
    {
        $this->doc('branch', 102);
        DB::connection('sync_branch')->table('docs')->where('id', 102)->update(['total' => 99]);
        $feed = $this->branch->pending();
        $this->hub->apply([$feed[1]]); // the update arrives first
        $this->assertSame(99.0, (float) DB::connection('sync_hub')->table('docs')->where('id', 102)->value('total'));

        DB::connection('sync_branch')->table('docs')->where('id', 102)->delete();
        $this->hub->apply($this->branch->pending());
        $this->assertSame([], $this->ids('hub'));
    }

    public function test_head_office_master_data_reaches_the_branch_and_a_branch_edit_of_it_waits_for_a_person(): void
    {
        DB::connection('sync_hub')->table('parties')->insert(['id' => 1, 'name' => 'عميل', 'created_at' => '2026-07-01 10:00:00', 'updated_at' => '2026-07-01 10:00:00']);
        $this->assertSame(1, $this->branch->apply($this->hub->pending())['applied']);
        $this->assertSame([1], $this->ids('branch', 'parties'));

        // The branch edits the master row offline: it is not applied at the head office, it is queued.
        DB::connection('sync_branch')->table('parties')->where('id', 1)->update(['name' => 'اسم مختلف']);
        $result = $this->hub->apply($this->branch->pending());
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame('عميل', DB::connection('sync_hub')->table('parties')->value('name'));
        $this->assertSame('master_edited_on_branch', DB::connection('sync_hub')->table('sync_conflicts')->value('reason'));

        // A new master row made at the branch (a customer added at the till) is accepted.
        DB::connection('sync_branch')->table('parties')->insert(['id' => 102, 'name' => 'عميل جديد', 'created_at' => '2026-07-02 09:00:00', 'updated_at' => '2026-07-02 09:00:00']);
        $this->assertSame(1, $this->hub->apply($this->branch->pending(1))['applied']);
        $this->assertSame([1, 102], $this->ids('hub', 'parties'));
    }

    public function test_the_same_id_on_two_nodes_is_never_overwritten(): void
    {
        $this->doc('hub', 5, 'HQ-1');
        $this->doc('branch', 5, 'BR-1'); // the two nodes were not given separate id ranges

        $result = $this->hub->apply($this->branch->pending());
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame('HQ-1', DB::connection('sync_hub')->table('docs')->where('id', 5)->value('number'));
        $this->assertSame('id_collision', DB::connection('sync_hub')->table('sync_conflicts')->value('reason'));
    }

    public function test_separate_id_ranges_never_meet(): void
    {
        // Node n steps by 100 from n (MariaDB auto_increment_increment=100, auto_increment_offset=n).
        foreach (range(0, 4) as $i) {
            $this->doc('hub', 1 + 100 * $i);
            $this->doc('branch', 2 + 100 * $i);
        }
        $this->assertSame(['applied' => 5, 'skipped' => 0, 'conflicts' => 0], $this->hub->apply($this->branch->pending()));
        $this->assertSame(['applied' => 5, 'skipped' => 0, 'conflicts' => 0], $this->branch->apply($this->hub->pending(0, 500, 2)));
        $this->assertSame($this->ids('hub'), $this->ids('branch'));
        $this->assertCount(10, $this->ids('hub'));
    }

    public function test_the_mysql_triggers_are_generated_for_every_event_and_respect_the_applying_flag(): void
    {
        $sql = implode("\n", SyncTriggers::statements('mysql', 'docs', ['id', 'number', 'total']));
        foreach (['AFTER INSERT', 'AFTER UPDATE', 'AFTER DELETE', 'JSON_OBJECT', 'applying', 'DROP TRIGGER IF EXISTS sync_docs_ai'] as $needle) {
            $this->assertStringContainsString($needle, $sql);
        }
    }
}

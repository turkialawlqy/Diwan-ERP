<?php

namespace Tests\Feature;

use App\Services\Sync\SyncEngine;
use App\Services\Sync\SyncTriggers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The same random work as SyncChaosTest on a shape closer to the real system: master data (parties) that only the head office edits and
 * branches only add to, documents with child lines (created, edited and deleted together), and a link table. Whatever the link does,
 * all three servers must end with exactly the same rows in every table.
 */
class SyncChaosWideTest extends TestCase
{
    use RefreshDatabase;

    protected const NODES = ['hub' => 1, 'b' => 2, 'c' => 3];

    /** @var array<string, SyncEngine> */
    protected array $engines = [];

    /** @var array<string, array{up: int, down: int}> */
    protected array $cursors = [];

    /** @var array<string, int> */
    protected array $nextId = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->build();
    }

    protected function build(): void
    {
        config(['sync-node.settle' => 0, 'sync.master' => ['parties'], 'sync.owned' => ['docs', 'doc_lines', 'doc_tags'], 'sync.derived' => [], 'sync.local' => []]);
        foreach (self::NODES as $name => $node) {
            config(["database.connections.sync_{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
            DB::purge("sync_{$name}");
            $schema = DB::connection("sync_{$name}")->getSchemaBuilder();
            $schema->create('parties', function (Blueprint $t) {
                $t->id();
                $t->string('name');
            });
            $schema->create('docs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('party_id')->nullable();
                $t->decimal('total', 12, 2)->default(0);
            });
            $schema->create('doc_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('doc_id');
                $t->integer('qty');
            });
            $schema->create('doc_tags', function (Blueprint $t) {
                $t->unsignedBigInteger('doc_id');
                $t->unsignedBigInteger('tag_id');
            });
            $this->engines[$name] = new SyncEngine("sync_{$name}", $name === 'hub' ? 'hub' : 'branch');
            $this->engines[$name]->join($node);
            SyncTriggers::install(DB::connection("sync_{$name}"), ['parties', 'docs', 'doc_lines', 'doc_tags']);
            $this->nextId[$name] = $node;
            $this->cursors[$name] = ['up' => 0, 'down' => 0];
        }
    }

    protected function t(string $name, string $table)
    {
        return DB::connection("sync_{$name}")->table($table);
    }

    protected function id(string $name): int
    {
        $id = $this->nextId[$name];
        $this->nextId[$name] += 100;

        return $id;
    }

    protected function work(string $name): void
    {
        $mod = self::NODES[$name];
        $docs = $this->t($name, 'docs')->whereRaw('id % 100 = ?', [$mod])->pluck('id')->all();
        $roll = mt_rand(1, 12);
        if ($roll <= 3) {
            $this->t($name, 'parties')->insert(['id' => $this->id($name), 'name' => "party-{$name}-".mt_rand()]);
        } elseif ($roll === 4 && $name === 'hub') {
            // Only the head office edits or removes master data.
            $party = $this->t($name, 'parties')->whereRaw('id % 100 = 1')->pluck('id')->all();
            if ($party) {
                $pick = $party[array_rand($party)];
                mt_rand(0, 1) ? $this->t($name, 'parties')->where('id', $pick)->update(['name' => 'edited-'.mt_rand()]) : $this->t($name, 'parties')->where('id', $pick)->delete();
            }
        } elseif ($roll <= 7 || ! $docs) {
            $docId = $this->id($name);
            $parties = $this->t($name, 'parties')->pluck('id')->all();
            $this->t($name, 'docs')->insert(['id' => $docId, 'party_id' => $parties ? $parties[array_rand($parties)] : null, 'total' => mt_rand(1, 999)]);
            foreach (range(1, mt_rand(1, 3)) as $i) {
                $this->t($name, 'doc_lines')->insert(['id' => $this->id($name), 'doc_id' => $docId, 'qty' => mt_rand(1, 20)]);
            }
            $this->t($name, 'doc_tags')->insert(['doc_id' => $docId, 'tag_id' => mt_rand(1, 5)]);
        } elseif ($roll <= 10) {
            $docId = $docs[array_rand($docs)];
            $this->t($name, 'docs')->where('id', $docId)->update(['total' => mt_rand(1, 999)]);
            $this->t($name, 'doc_lines')->where('doc_id', $docId)->update(['qty' => mt_rand(1, 20)]);
            if (mt_rand(0, 1)) {
                $this->t($name, 'doc_tags')->where('doc_id', $docId)->update(['tag_id' => mt_rand(1, 5)]);
            }
        } else {
            $docId = $docs[array_rand($docs)];
            $this->t($name, 'doc_lines')->where('doc_id', $docId)->delete();
            $this->t($name, 'doc_tags')->where('doc_id', $docId)->delete();
            $this->t($name, 'docs')->where('id', $docId)->delete();
        }
    }

    protected function exchange(string $branch, bool $faults): void
    {
        if ($faults && mt_rand(1, 10) <= 3) {
            return;
        }
        $limit = $faults ? mt_rand(1, 8) : 1000;
        $lost = fn () => $faults && mt_rand(1, 10) <= 2;

        $up = $this->engines[$branch]->pending($this->cursors[$branch]['up'], $limit);
        if ($up) {
            $this->engines['hub']->apply($up);
            if ($lost()) {
                $this->engines['hub']->apply($up);   // sent twice
            }
            if (! $lost()) {
                $this->cursors[$branch]['up'] = max(array_column($up, 'feed'));
            }
        }
        $down = $this->engines['hub']->pending($this->cursors[$branch]['down'], $limit, self::NODES[$branch]);
        if ($down) {
            $this->engines[$branch]->apply($down);
            if (! $lost()) {
                $this->cursors[$branch]['down'] = max(array_column($down, 'feed'));
            }
        } else {
            $this->cursors[$branch]['down'] = max($this->cursors[$branch]['down'], $this->engines['hub']->ceiling());
        }
    }

    protected function snapshot(string $name): array
    {
        $out = [];
        foreach (['parties' => 'id', 'docs' => 'id', 'doc_lines' => 'id', 'doc_tags' => 'doc_id'] as $table => $order) {
            $out[$table] = $this->t($name, $table)->orderBy($order)->orderBy($table === 'doc_tags' ? 'tag_id' : 'id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    public function test_three_servers_converge_on_master_data_documents_lines_and_links(): void
    {
        foreach ([3, 11, 77, 2026, 90210] as $seed) {
            $this->build();
            mt_srand($seed);
            foreach (range(1, 220) as $step) {
                $this->work(array_keys(self::NODES)[mt_rand(0, 2)]);
                if (mt_rand(1, 3) === 1) {
                    $this->exchange(['b', 'c'][mt_rand(0, 1)], faults: true);
                }
            }
            foreach (range(1, 5) as $round) {
                $this->exchange('b', faults: false);
                $this->exchange('c', faults: false);
            }

            $hub = $this->snapshot('hub');
            $this->assertSame($hub, $this->snapshot('b'), "seed {$seed}: branch B differs from the head office");
            $this->assertSame($hub, $this->snapshot('c'), "seed {$seed}: branch C differs from the head office");
            $this->assertNotEmpty($hub['docs']);
            $this->assertNotEmpty($hub['doc_lines']);
        }
    }
}

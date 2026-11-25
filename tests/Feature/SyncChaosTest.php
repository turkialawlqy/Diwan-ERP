<?php

namespace Tests\Feature;

use App\Services\Sync\SyncEngine;
use App\Services\Sync\SyncTriggers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A head office and two branches doing random work while the link drops, answers are lost and batches are sent twice.
 * Whatever happens in between, once the link holds long enough all three must hold exactly the same documents.
 */
class SyncChaosTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, SyncEngine> */
    protected array $engines = [];

    /** @var array<string, array{up: int, down: int}> cursors per branch: what it sent, what it received */
    protected array $cursors = [];

    /** @var array<string, int> */
    protected array $nextId = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->build();
    }

    /** Three fresh in-memory servers. */
    protected function build(): void
    {
        config(['sync-node.settle' => 0, 'sync.master' => [], 'sync.owned' => ['docs'], 'sync.derived' => [], 'sync.local' => []]);
        foreach (['hub' => 1, 'b' => 2, 'c' => 3] as $name => $node) {
            config(["database.connections.sync_{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
            DB::purge("sync_{$name}");
            DB::connection("sync_{$name}")->getSchemaBuilder()->create('docs', function (Blueprint $t) {
                $t->id();
                $t->string('number');
                $t->decimal('total', 12, 2)->default(0);
                $t->string('note')->nullable();
            });
            $this->engines[$name] = new SyncEngine("sync_{$name}", $name === 'hub' ? 'hub' : 'branch');
            $this->engines[$name]->join($node);
            SyncTriggers::install(DB::connection("sync_{$name}"), ['docs']);
            $this->nextId[$name] = $node;
            $this->cursors[$name] = ['up' => 0, 'down' => 0];
        }
    }

    protected function table(string $name)
    {
        return DB::connection("sync_{$name}")->table('docs');
    }

    /** One random thing a user does on one server — only to the documents that server made itself. */
    protected function work(string $name): void
    {
        $mine = $this->table($name)->whereRaw('id % 100 = ?', [['hub' => 1, 'b' => 2, 'c' => 3][$name]])->pluck('id')->all();
        $roll = mt_rand(1, 10);
        if ($roll <= 5 || ! $mine) {
            $id = $this->nextId[$name];
            $this->nextId[$name] += 100;
            $this->table($name)->insert(['id' => $id, 'number' => "{$name}-{$id}", 'total' => mt_rand(1, 9999) / 10]);
        } elseif ($roll <= 8) {
            $this->table($name)->where('id', $mine[array_rand($mine)])->update(['total' => mt_rand(1, 9999) / 10, 'note' => 'edit'.mt_rand()]);
        } else {
            $this->table($name)->where('id', $mine[array_rand($mine)])->delete();
        }
    }

    /** One attempt to exchange with the head office; $faults makes the link unreliable. */
    protected function exchange(string $branch, bool $faults): void
    {
        if ($faults && mt_rand(1, 10) <= 3) {
            return;   // the link is down
        }
        $limit = $faults ? mt_rand(1, 6) : 500;

        $up = $this->engines[$branch]->pending($this->cursors[$branch]['up'], $limit);
        if ($up) {
            $this->engines['hub']->apply($up);
            if ($faults && mt_rand(1, 10) <= 2) {
                $this->engines['hub']->apply($up);   // sent twice
            }
            if (! ($faults && mt_rand(1, 10) <= 2)) {   // otherwise the answer was lost: send it all again next time
                $this->cursors[$branch]['up'] = max(array_column($up, 'feed'));
            }
        }

        $down = $this->engines['hub']->pending($this->cursors[$branch]['down'], $limit, ['b' => 2, 'c' => 3][$branch]);
        if ($down) {
            $this->engines[$branch]->apply($down);
            if (! ($faults && mt_rand(1, 10) <= 2)) {
                $this->cursors[$branch]['down'] = max(array_column($down, 'feed'));
            }
        }
        // Past everything the hub holds that is not this branch's own, so the next question starts there.
        if (! $down) {
            $this->cursors[$branch]['down'] = max($this->cursors[$branch]['down'], $this->engines['hub']->ceiling());
        }
    }

    protected function snapshot(string $name): array
    {
        return $this->table($name)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_three_servers_converge_whatever_the_link_does(): void
    {
        foreach ([1, 7, 42, 2026, 31337] as $seed) {
            $this->build();
            mt_srand($seed);
            foreach (range(1, 150) as $step) {
                $this->work(['hub', 'b', 'c'][mt_rand(0, 2)]);
                if (mt_rand(1, 3) === 1) {
                    $this->exchange(['b', 'c'][mt_rand(0, 1)], faults: true);
                }
            }
            // The link holds: a few clean rounds are enough for the news to cross the head office both ways.
            foreach (range(1, 4) as $round) {
                $this->exchange('b', faults: false);
                $this->exchange('c', faults: false);
            }

            $this->assertSame($this->snapshot('hub'), $this->snapshot('b'), "seed {$seed}: branch B differs from the head office");
            $this->assertSame($this->snapshot('hub'), $this->snapshot('c'), "seed {$seed}: branch C differs from the head office");
            $this->assertNotEmpty($this->snapshot('hub'));
        }
    }
}

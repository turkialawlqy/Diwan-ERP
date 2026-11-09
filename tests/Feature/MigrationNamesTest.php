<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MySQL refuses an identifier longer than 64 characters and SQLite, where the suite usually runs,
 * does not: a migration whose generated index name is too long passes here and stops every MySQL
 * install at that migration. The names are checked here instead (a long one gets an explicit name).
 */
class MigrationNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_index_name_fits_mysql(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Read from the SQLite schema; MySQL enforces the limit itself.');
        }

        $long = collect(DB::select("select name from sqlite_master where type = 'index' and name not like 'sqlite_autoindex_%'"))
            ->pluck('name')->filter(fn ($name) => strlen($name) > 64)->values()->all();

        $this->assertSame([], $long, 'Index names longer than MySQL allows (64): '.implode(', ', $long));
    }
}

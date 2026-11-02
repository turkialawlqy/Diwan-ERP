<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Up to 1.6.0, erp:zatca-submit queued one more SubmitZatcaDocument for every
 * waiting document every 10 minutes, and office installs ran no worker on the
 * zatca queue: their jobs table holds a copy per document for each run, which a
 * worker would go through before the mail and alerts on "default". They are
 * dropped; the next erp:zatca-submit run queues one job per document still waiting.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('jobs')->where('queue', 'zatca')->where('payload', 'like', '%SubmitZatcaDocument%')->select('id')
            ->chunkById(1000, fn ($jobs) => DB::table('jobs')->whereIn('id', $jobs->pluck('id'))->delete());
    }

    public function down(): void
    {
        // Nothing to restore: erp:zatca-submit queues the documents still waiting.
    }
};

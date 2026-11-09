<?php

use App\Models\SlaPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The time a ticket has spent waiting for the customer, kept so that a change of priority moves
 * the resolution deadline without dropping it. Open tickets get what their deadline already holds:
 * the deadline minus the opening plus the policy's time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('sla_paused_seconds')->default(0);
        });

        $minutes = [];
        DB::table('tickets')->whereIn('status', ['open', 'pending'])->whereNotNull('resolution_due_at')->whereNotNull('created_at')
            ->select(['id', 'company_id', 'priority', 'created_at', 'resolution_due_at'])
            ->chunkById(500, function ($tickets) use (&$minutes) {
                foreach ($tickets as $t) {
                    $key = $t->company_id.'|'.$t->priority;
                    $minutes[$key] ??= (int) (DB::table('sla_policies')->where('company_id', $t->company_id)->where('priority', $t->priority)->value('resolution_minutes')
                        ?? (SlaPolicy::DEFAULTS[$t->priority] ?? SlaPolicy::DEFAULTS['normal'])[1]);
                    $waited = Carbon::parse($t->resolution_due_at)->getTimestamp() - Carbon::parse($t->created_at)->addMinutes($minutes[$key])->getTimestamp();
                    if ($waited > 0) {
                        DB::table('tickets')->where('id', $t->id)->update(['sla_paused_seconds' => $waited]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('sla_paused_seconds');
        });
    }
};

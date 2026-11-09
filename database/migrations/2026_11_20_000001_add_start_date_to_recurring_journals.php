<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first run's date is what a monthly journal on the 31st comes back to after a short month.
 * Templates that already ran take it from their first entry (their next date may have slipped to the 28th).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_journals', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('frequency');
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        DB::table('recurring_journals')->whereNull('start_date')->orderBy('id')->each(function ($template) {
            $first = DB::table('journal_entries')->where('company_id', $template->company_id)
                ->where('entry_type', 'recurring')->where('source_id', $template->id)->min('date');
            DB::table('recurring_journals')->where('id', $template->id)->update(['start_date' => $first ? substr((string) $first, 0, 10) : $template->next_run_date]);
        });
    }

    public function down(): void
    {
        Schema::table('recurring_journals', fn (Blueprint $table) => $table->dropColumn('start_date'));
    }
};

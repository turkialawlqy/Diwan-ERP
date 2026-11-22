<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Assets saved from the fixed-asset screen had no branch, so each later entry (depreciation, impairment,
 * disposal) took whichever branch the top bar showed at the time. An asset belongs where it was bought:
 * its purchase entry's branch (an entry without one leaves the asset as it is).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('fixed_assets')->whereNull('branch_id')->whereNotNull('acquisition_entry_id')->update([
            'branch_id' => DB::raw('(SELECT je.branch_id FROM journal_entries je WHERE je.id = fixed_assets.acquisition_entry_id)'),
        ]);
    }

    public function down(): void
    {
        // Kept: an asset without a branch was the defect.
    }
};

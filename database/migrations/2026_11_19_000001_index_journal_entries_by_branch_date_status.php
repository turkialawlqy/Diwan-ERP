<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A branch's reports read its entries of a period. With the status in the index that reading
 * never touches the table, and it ends where the period ends, however long the ledger is.
 * It replaces the index of the first three columns, which added nothing to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('journal_entries', 'branch_id')) {
            return;
        }

        if (! Schema::hasIndex('journal_entries', 'journal_entries_branch_date_status_index')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->index(['company_id', 'branch_id', 'date', 'status'], 'journal_entries_branch_date_status_index'));
        }
        if (Schema::hasIndex('journal_entries', 'journal_entries_branch_date_index')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->dropIndex('journal_entries_branch_date_index'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('journal_entries', 'branch_id')) {
            return;
        }

        if (! Schema::hasIndex('journal_entries', 'journal_entries_branch_date_index')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->index(['company_id', 'branch_id', 'date'], 'journal_entries_branch_date_index'));
        }
        if (Schema::hasIndex('journal_entries', 'journal_entries_branch_date_status_index')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->dropIndex('journal_entries_branch_date_status_index'));
        }
    }
};

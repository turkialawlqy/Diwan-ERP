<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profit reports look up the year-end closing entries of the company on every run:
 * a handful of rows that, without this index, cost a read of every journal entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('journal_entries', 'journal_entries_company_type_index')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->index(['company_id', 'entry_type'], 'journal_entries_company_type_index'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('journal_entries', 'journal_entries_company_type_index')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->dropIndex('journal_entries_company_type_index'));
        }
    }
};

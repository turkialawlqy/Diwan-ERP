<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports read document lines through their parent (join on the parent id).
 * MySQL indexes every foreign key, SQLite does not: without these indexes the
 * VAT return scanned every purchase invoice line once per invoice of the period.
 */
return new class extends Migration
{
    /** table => [column, index name] */
    protected array $indexes = [
        'purchase_invoice_items' => ['purchase_invoice_id', 'purchase_invoice_items_purchase_invoice_id_index'],
        'journal_entry_lines' => ['journal_entry_id', 'journal_entry_lines_journal_entry_id_index'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => [$column, $name]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // An index already led by the column (MySQL's foreign key index) serves the join.
            $indexed = collect(Schema::getIndexes($table))->contains(fn ($index) => ($index['columns'][0] ?? null) === $column);
            if (! $indexed) {
                Schema::table($table, fn (Blueprint $t) => $t->index($column, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => [, $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};

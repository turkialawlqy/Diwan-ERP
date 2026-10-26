<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Line-item tables previously inherited their tenant only through their parent
 * document, so a line could reference another company's parent or account and
 * aggregate reports over lines (trial balance, item sales) had to join back to
 * the parent to stay isolated. Give every line its own indexed company_id.
 */
return new class extends Migration
{
    /** table => [parent table, foreign key] */
    protected array $tables = [
        'invoice_items' => ['invoices', 'invoice_id'],
        'quotation_items' => ['quotations', 'quotation_id'],
        'journal_entry_lines' => ['journal_entries', 'journal_entry_id'],
        'payroll_items' => ['payrolls', 'payroll_id'],
        'pos_order_items' => ['pos_orders', 'pos_order_id'],
        'purchase_invoice_items' => ['purchase_invoices', 'purchase_invoice_id'],
        'purchase_order_items' => ['purchase_orders', 'purchase_order_id'],
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => [$parent, $foreignKey]) {
            if (Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->after('id');
                $t->index('company_id');
            });

            DB::statement(
                "UPDATE {$table} SET company_id = (SELECT {$parent}.company_id FROM {$parent} WHERE {$parent}.id = {$table}.{$foreignKey})"
            );
        }

        Schema::table('journal_entry_lines', function (Blueprint $t) {
            $t->index(['company_id', 'account_id'], 'jel_company_account_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $t) {
            $t->dropIndex('jel_company_account_idx');
        });

        foreach (array_keys($this->tables) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['company_id']);
                $t->dropColumn('company_id');
            });
        }
    }
};

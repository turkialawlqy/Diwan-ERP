<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes on company_id for all tenant-scoped tables.
     * These composite indexes dramatically improve query performance
     * when multiple companies share the same database.
     */
    public function up(): void
    {
        $tables = [
            'customers' => ['company_id', 'created_at'],
            'invoices' => ['company_id', 'invoice_date'],
            'invoice_items' => ['invoice_id'],
            'quotations' => ['company_id', 'created_at'],
            'credit_notes' => ['company_id', 'credit_note_date'],
            'customer_payments' => ['company_id', 'created_at'],
            'products' => ['company_id', 'is_active'],
            'categories' => ['company_id'],
            'branches' => ['company_id'],
            'suppliers' => ['company_id'],
            'purchase_invoices' => ['company_id', 'invoice_date'],
            'purchase_orders' => ['company_id'],
            'supplier_payments' => ['company_id'],
            'accounts' => ['company_id'],
            'journal_entries' => ['company_id', 'date'],
            'expenses' => ['company_id', 'expense_date'],
            'revenues' => ['company_id', 'revenue_date'],
            'bank_accounts' => ['company_id'],
            'pos_sessions' => ['company_id', 'user_id'],
            'pos_orders' => ['company_id', 'created_at'],
            'employees' => ['company_id'],
            'payrolls' => ['company_id'],
        ];

        foreach ($tables as $table => $columns) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $blueprint) use ($columns, $table) {
                    $indexName = $table.'_'.implode('_', $columns).'_index';
                    // Only add if index doesn't exist
                    if (! Schema::hasIndex($table, $indexName)) {
                        $blueprint->index($columns, $indexName);
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'customers' => ['company_id', 'created_at'],
            'invoices' => ['company_id', 'invoice_date'],
            'invoice_items' => ['invoice_id'],
            'quotations' => ['company_id', 'created_at'],
            'credit_notes' => ['company_id', 'credit_note_date'],
            'customer_payments' => ['company_id', 'created_at'],
            'products' => ['company_id', 'is_active'],
            'categories' => ['company_id'],
            'branches' => ['company_id'],
            'suppliers' => ['company_id'],
            'purchase_invoices' => ['company_id', 'invoice_date'],
            'purchase_orders' => ['company_id'],
            'supplier_payments' => ['company_id'],
            'accounts' => ['company_id'],
            'journal_entries' => ['company_id', 'date'],
            'expenses' => ['company_id', 'expense_date'],
            'revenues' => ['company_id', 'revenue_date'],
            'bank_accounts' => ['company_id'],
            'pos_sessions' => ['company_id', 'user_id'],
            'pos_orders' => ['company_id', 'created_at'],
            'employees' => ['company_id'],
            'payrolls' => ['company_id'],
        ];

        foreach ($tables as $table => $columns) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $blueprint) use ($columns, $table) {
                    $indexName = $table.'_'.implode('_', $columns).'_index';
                    $blueprint->dropIndex($indexName);
                });
            }
        }
    }
};

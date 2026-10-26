<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for multi-million row tables (every hot query filters by company
 * first) and per-company uniqueness of document numbers.
 */
return new class extends Migration
{
    /** table => [ [columns], name ] — per-company unique document numbers. */
    protected array $uniques = [
        'purchase_orders' => [['company_id', 'order_number'], 'po_company_number_unique'],
        'purchase_invoices' => [['company_id', 'invoice_number'], 'pi_company_number_unique'],
        'supplier_payments' => [['company_id', 'payment_number'], 'sp_company_number_unique'],
        'pos_orders' => [['company_id', 'order_number'], 'pos_company_number_unique'],
    ];

    /** Plain composite indexes. */
    protected array $indexes = [
        ['invoices', ['company_id', 'customer_id', 'invoice_date'], 'inv_company_customer_date_idx'],
        ['invoices', ['company_id', 'payment_status'], 'inv_company_status_idx'],
        ['quotations', ['company_id', 'quotation_date'], 'quo_company_date_idx'],
        ['credit_notes', ['company_id', 'customer_id'], 'cn_company_customer_idx'],
        ['customer_payments', ['company_id', 'payment_date'], 'cp_company_date_idx'],
        ['customer_payments', ['company_id', 'customer_id'], 'cp_company_customer_idx'],
        ['supplier_payments', ['company_id', 'payment_date'], 'sp_company_date_idx'],
        ['supplier_payments', ['company_id', 'supplier_id'], 'sp_company_supplier_idx'],
        ['purchase_orders', ['company_id', 'order_date'], 'po_company_date_idx'],
        ['purchase_invoices', ['company_id', 'supplier_id', 'invoice_date'], 'pi_company_supplier_date_idx'],
        ['purchase_invoices', ['company_id', 'supplier_id', 'supplier_invoice_number'], 'pi_supplier_ref_idx'],
        ['journal_entry_lines', ['account_id', 'journal_entry_id'], 'jel_account_entry_idx'],
        ['inventory_stocks', ['company_id', 'product_id'], 'stock_company_product_idx'],
        ['products', ['company_id', 'barcode'], 'products_company_barcode_idx'],
        ['products', ['company_id', 'category_id'], 'products_company_category_idx'],
        ['customers', ['company_id', 'name'], 'customers_company_name_idx'],
        ['customers', ['company_id', 'phone'], 'customers_company_phone_idx'],
        ['suppliers', ['company_id', 'name'], 'suppliers_company_name_idx'],
        ['employees', ['company_id', 'status'], 'employees_company_status_idx'],
        ['users', ['company_id', 'status'], 'users_company_status_idx'],
        ['audit_logs', ['company_id', 'auditable_type', 'auditable_id'], 'audit_company_subject_idx'],
    ];

    public function up(): void
    {
        foreach ($this->uniques as $table => [$columns, $name]) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                continue;
            }

            if ($this->hasDuplicates($table, $columns)) {
                // Never fail a production upgrade on legacy duplicates: fall back to a
                // plain index and report so the data can be cleaned up.
                Log::warning("Duplicate values in {$table}(".implode(',', $columns).'); unique index skipped.');
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));

                continue;
            }

            Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
        }

        foreach ($this->indexes as [$table, $columns, $name]) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue 2;
                }
            }

            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, , $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }

        foreach ($this->uniques as $table => [, $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, function (Blueprint $t) use ($table, $name) {
                    Schema::hasIndex($table, $name, 'unique') ? $t->dropUnique($name) : $t->dropIndex($name);
                });
            }
        }
    }

    protected function hasDuplicates(string $table, array $columns): bool
    {
        return DB::table($table)
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }
};

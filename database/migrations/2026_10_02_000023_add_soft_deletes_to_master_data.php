<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data referenced by historical documents is archived (soft deleted)
 * instead of physically removed, so old invoices, journals and reports keep
 * resolving their customer / product / account names.
 */
return new class extends Migration
{
    protected array $tables = ['customers', 'suppliers', 'products', 'employees', 'warehouses', 'branches', 'bank_accounts', 'categories'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->softDeletes()->index());
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['deleted_at']);
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};

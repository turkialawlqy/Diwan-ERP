<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents that move money record which cash box / bank account was used,
 * so their journal entries hit the right ledger account.
 */
return new class extends Migration
{
    protected array $tables = ['customer_payments', 'supplier_payments', 'purchase_invoices', 'credit_notes', 'pos_registers', 'payrolls'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'bank_account_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
                });
            }
        }

        Schema::table('credit_notes', function (Blueprint $t) {
            $t->string('refund_method', 20)->nullable()->after('status'); // credit (to customer balance), cash, bank_transfer
        });

        foreach (['expenses', 'revenues'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['expenses', 'revenues'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('cost_center_id');
            });
        }

        Schema::table('credit_notes', fn (Blueprint $t) => $t->dropColumn('refund_method'));

        foreach ($this->tables as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('bank_account_id'));
        }
    }
};

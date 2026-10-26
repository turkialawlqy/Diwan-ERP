<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelled documents stay on record (numbering, audit) with a reversal
 * entry; cheques remember the voucher that created them.
 */
return new class extends Migration
{
    protected array $documents = ['customer_payments', 'supplier_payments', 'delivery_notes', 'goods_receipts'];

    public function up(): void
    {
        foreach ($this->documents as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'status')) {
                    $t->string('status', 20)->default('posted');
                }
                if (! Schema::hasColumn($table, 'cancelled_at')) {
                    $t->timestamp('cancelled_at')->nullable();
                    $t->foreignId('cancelled_by')->nullable();
                    $t->string('cancel_reason')->nullable();
                }
            });
        }

        if (! Schema::hasColumn('cheques', 'source_type')) {
            Schema::table('cheques', function (Blueprint $t) {
                $t->string('source_type', 100)->nullable();
                $t->unsignedBigInteger('source_id')->nullable();
                $t->index(['source_type', 'source_id'], 'cheques_source_idx');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->documents as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason']);
                if (in_array($table, ['customer_payments', 'supplier_payments'], true)) {
                    $t->dropColumn('status');
                }
            });
        }
        Schema::table('cheques', function (Blueprint $t) {
            $t->dropIndex('cheques_source_idx');
            $t->dropColumn(['source_type', 'source_id']);
        });
    }
};

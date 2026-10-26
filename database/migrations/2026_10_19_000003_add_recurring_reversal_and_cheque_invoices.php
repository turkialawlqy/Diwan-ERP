<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accruals reversed on the next day, and failures kept per template instead of stopping the run.
        Schema::table('recurring_journals', function (Blueprint $t) {
            if (! Schema::hasColumn('recurring_journals', 'auto_reverse')) {
                $t->boolean('auto_reverse')->default(false);
                $t->text('last_error')->nullable();
                $t->timestamp('last_error_at')->nullable();
            }
        });

        // A cheque registered against a specific invoice settles it.
        Schema::table('cheques', function (Blueprint $t) {
            if (! Schema::hasColumn('cheques', 'invoice_id')) {
                $t->unsignedBigInteger('invoice_id')->nullable();
                $t->unsignedBigInteger('purchase_invoice_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('recurring_journals', fn (Blueprint $t) => $t->dropColumn(['auto_reverse', 'last_error', 'last_error_at']));
        Schema::table('cheques', fn (Blueprint $t) => $t->dropColumn(['invoice_id', 'purchase_invoice_id']));
    }
};

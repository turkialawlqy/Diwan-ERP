<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Split tender: the cash and card parts of a POS order.
        if (! Schema::hasColumn('pos_orders', 'cash_amount')) {
            Schema::table('pos_orders', function (Blueprint $t) {
                $t->decimal('cash_amount', 12, 2)->default(0);
                $t->decimal('card_amount', 12, 2)->default(0);
            });
        }

        if (! Schema::hasTable('zakat_assessments')) {
            Schema::create('zakat_assessments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
                $t->decimal('base', 18, 2);
                $t->decimal('rate', 8, 4);
                $t->decimal('amount', 18, 2);
                $t->json('details')->nullable();
                $t->foreignId('journal_entry_id')->nullable();
                $t->foreignId('created_by')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'fiscal_year_id'], 'zakat_company_year_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zakat_assessments');
        Schema::table('pos_orders', fn (Blueprint $t) => $t->dropColumn(['cash_amount', 'card_amount']));
    }
};

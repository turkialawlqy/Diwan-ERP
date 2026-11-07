<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The unit a purchase line was bought in (carton...) and how many base units it holds, so returns issue what really came in. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_invoice_items', 'goods_receipt_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('unit_id')->nullable();
                $t->decimal('unit_factor', 14, 6)->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_invoice_items', 'goods_receipt_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['unit_id', 'unit_factor']);
            });
        }
    }
};

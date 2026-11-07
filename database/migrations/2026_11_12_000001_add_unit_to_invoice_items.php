<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The unit a line was sold in (carton...) and how many base units it holds, so returns restock what really left. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('unit_factor', 14, 6)->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['unit_id', 'unit_factor']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Retention held back from a subcontractor and released to him later (his side of «الإفراج عن المحتجزات»). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcontracts', function (Blueprint $table) {
            $table->decimal('retention_released', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('subcontracts', function (Blueprint $table) {
            $table->dropColumn('retention_released');
        });
    }
};

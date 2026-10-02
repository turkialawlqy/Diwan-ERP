<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('average_cost', 18, 4)->default(0)->after('purchase_price');
        });

        // Start the moving average from the current purchase price.
        DB::table('products')->update(['average_cost' => DB::raw('COALESCE(purchase_price, 0)')]);
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('average_cost'));
    }
};

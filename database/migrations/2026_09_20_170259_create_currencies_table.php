<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('code', 3);
            $table->string('symbol_ar');
            $table->string('symbol_en');
            $table->decimal('exchange_rate', 12, 6)->default(1.000000);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};

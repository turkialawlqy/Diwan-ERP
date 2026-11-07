<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The 13-week cash forecast as it stood on a day, kept to be compared later with what actually happened. */
    public function up(): void
    {
        Schema::create('cash_forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 80)->default('all');   // branch scope the forecast was taken for (ActiveBranch::scopeKey)
            $table->date('taken_on');
            $table->decimal('opening', 18, 2)->default(0);
            $table->json('weeks');
            $table->json('scenario')->nullable();
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'scope', 'taken_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_forecast_snapshots');
    }
};

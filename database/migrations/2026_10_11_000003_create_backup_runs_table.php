<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level (not per company): one row per backup or verification run.
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->default('backup'); // backup, verify
            $table->string('file')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status', 10); // ok, failed
            $table->json('details')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};

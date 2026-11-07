<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Indirect (overhead) costs spread over projects by revenue, direct cost or hours. */
    public function up(): void
    {
        Schema::create('project_allocation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 50);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('basis', 20);                       // revenue, direct_cost, hours
            $table->json('pool_account_ids');
            $table->decimal('pool_amount', 18, 2);
            $table->string('status', 20)->default('posted');   // posted, reversed
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('project_allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('project_allocation_runs')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->decimal('basis_value', 18, 2);
            $table->decimal('share_pct', 8, 4);
            $table->decimal('amount', 18, 2);
            $table->foreignId('project_cost_id')->nullable()->constrained('project_costs')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_allocation_lines');
        Schema::dropIfExists('project_allocation_runs');
    }
};

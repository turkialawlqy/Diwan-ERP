<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A budget line may be set for a project or a branch (the cost centre column already existed): account-only lines keep all three null. */
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('cost_center_id')->constrained('projects')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('project_id')->constrained('branches')->nullOnDelete();
            $table->index(['budget_id', 'account_id', 'cost_center_id', 'project_id', 'branch_id'], 'budget_lines_dim_idx');
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropIndex('budget_lines_dim_idx');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('project_id');
        });
    }
};

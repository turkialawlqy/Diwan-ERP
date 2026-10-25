<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches a user may work in (users.branch_id stays as the default one).
 * Existing users keep exactly their current branch; a user without one gets
 * the company's main branch. The company owner needs no rows: all branches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'branch_id']);
        });

        $owners = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->where('roles.name', config('erp_permissions.owner_role', 'admin'))
            ->pluck('model_has_roles.model_id')->all();

        $mainBranch = DB::table('branches')->orderByDesc('is_main')->orderBy('id')->get(['id', 'company_id'])
            ->unique('company_id')->pluck('id', 'company_id');

        DB::table('users')->whereNotNull('company_id')->whereNotIn('id', $owners)->orderBy('id')
            ->each(function ($user) use ($mainBranch) {
                $branchId = $user->branch_id ?: ($mainBranch[$user->company_id] ?? null);
                if ($branchId) {
                    DB::table('branch_user')->insert(['user_id' => $user->id, 'branch_id' => $branchId]);
                    if (! $user->branch_id) {
                        DB::table('users')->where('id', $user->id)->update(['branch_id' => $branchId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
    }
};

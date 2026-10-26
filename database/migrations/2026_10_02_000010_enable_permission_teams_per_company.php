<?php

use App\Models\Company;
use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles used to be global (shared by every company). Switch Spatie to "teams"
 * mode with team_id = company_id so every company owns its own roles and
 * role assignments, then re-create each company's roles and re-attach users.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $team = config('permission.column_names.team_foreign_key', 'team_id');

        // 1. Remember existing assignments (user -> role name) before rebuilding.
        $assignments = DB::table($tables['model_has_roles'])
            ->join($tables['roles'], $tables['roles'].'.id', '=', $tables['model_has_roles'].'.role_id')
            ->join('users', 'users.id', '=', $tables['model_has_roles'].'.model_id')
            ->where($tables['model_has_roles'].'.model_type', 'App\\Models\\User')
            ->get(['users.id as user_id', 'users.company_id', $tables['roles'].'.name as role_name']);

        // Fresh installs already create team-aware tables (teams = true).
        if (! Schema::hasColumn($tables['model_has_roles'], $team)) {
            $this->rebuildTables($tables, $team);
        }

        $this->seedRoles($tables, $team, $assignments);
    }

    protected function rebuildTables(array $tables, string $team): void
    {
        // 2. Rebuild the pivot tables with a team column in their primary keys.
        Schema::drop($tables['model_has_permissions']);
        Schema::drop($tables['model_has_roles']);

        if (! Schema::hasColumn($tables['roles'], $team)) {
            Schema::table($tables['roles'], function (Blueprint $table) use ($team) {
                $table->unsignedBigInteger($team)->nullable()->after('id');
                $table->index($team, 'roles_team_foreign_key_index');
            });
        }

        Schema::table($tables['roles'], function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name']);
        });

        Schema::table($tables['roles'], function (Blueprint $table) use ($team) {
            $table->unique([$team, 'name', 'guard_name']);
        });

        Schema::create($tables['model_has_permissions'], function (Blueprint $table) use ($tables, $team) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger($team);
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->index($team, 'model_has_permissions_team_foreign_key_index');
            $table->foreign('permission_id')->references('id')->on($tables['permissions'])->onDelete('cascade');
            $table->primary([$team, 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create($tables['model_has_roles'], function (Blueprint $table) use ($tables, $team) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger($team);
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->index($team, 'model_has_roles_team_foreign_key_index');
            $table->foreign('role_id')->references('id')->on($tables['roles'])->onDelete('cascade');
            $table->primary([$team, 'role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

    }

    protected function seedRoles(array $tables, string $team, $assignments): void
    {
        // 3. Old global roles are replaced by per-company roles (the platform's
        //    super admin is identified by users.is_super_admin, not a role).
        DB::table($tables['roles'])->whereNull($team)->delete();

        // 4. Permissions + default roles for every company, then re-attach users.
        $registry = app(PermissionRegistry::class);
        $registry->syncPermissions();

        Company::query()->each(fn (Company $company) => $registry->seedCompanyRoles($company));

        foreach ($assignments as $row) {
            if (! $row->company_id) {
                continue;
            }

            $roleId = DB::table($tables['roles'])
                ->where($team, $row->company_id)
                ->where('name', $row->role_name)
                ->value('id');

            if (! $roleId) {
                // A custom role that existed globally: recreate it for this company.
                $roleId = DB::table($tables['roles'])->insertGetId([
                    $team => $row->company_id,
                    'name' => $row->role_name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table($tables['model_has_roles'])->insertOrIgnore([
                'role_id' => $roleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $row->user_id,
                $team => $row->company_id,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Irreversible data migration (per-company roles cannot be merged back safely).
    }
};

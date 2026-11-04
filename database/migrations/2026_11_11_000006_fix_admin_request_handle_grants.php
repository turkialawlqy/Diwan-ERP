<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * «Handle administrative requests» went to every role with self service; it belongs to the
 * roles that decide on staff matters (leave approval). Plain employees lose it: they see their
 * own requests in self service, and a department manager gets the permission on his role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['hr.admin_requests.handle', 'hr.self_service.use', 'hr.leaves.approve'])->pluck('id', 'name');
        if (! isset($ids['hr.admin_requests.handle'])) {
            return;
        }
        $deciders = isset($ids['hr.leaves.approve']) ? DB::table('role_has_permissions')->where('permission_id', $ids['hr.leaves.approve'])->pluck('role_id') : collect();
        if (isset($ids['hr.self_service.use'])) {
            $plain = DB::table('role_has_permissions')->where('permission_id', $ids['hr.self_service.use'])->whereNotIn('role_id', $deciders)->pluck('role_id');
            DB::table('role_has_permissions')->where('permission_id', $ids['hr.admin_requests.handle'])->whereIn('role_id', $plain)->delete();
        }
        $has = DB::table('role_has_permissions')->where('permission_id', $ids['hr.admin_requests.handle'])->pluck('role_id');
        $deciders->diff($has)->each(fn ($roleId) => DB::table('role_has_permissions')->insert(['permission_id' => $ids['hr.admin_requests.handle'], 'role_id' => $roleId]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants stay.
    }
};

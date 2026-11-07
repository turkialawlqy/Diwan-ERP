<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Roles that manage POS shifts may also sell above the cashier's discount cap. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistry::class)->syncPermissions();
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['pos.sessions.manage', 'pos.discounts.manage'])->pluck('id', 'name');
        if (isset($ids['pos.sessions.manage'], $ids['pos.discounts.manage'])) {
            $has = DB::table('role_has_permissions')->where('permission_id', $ids['pos.discounts.manage'])->pluck('role_id');
            DB::table('role_has_permissions')->where('permission_id', $ids['pos.sessions.manage'])->whereNotIn('role_id', $has)->pluck('role_id')
                ->each(fn ($roleId) => DB::table('role_has_permissions')->insert(['permission_id' => $ids['pos.discounts.manage'], 'role_id' => $roleId]));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants stay.
    }
};

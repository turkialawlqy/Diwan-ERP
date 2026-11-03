<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Roles that edit customers may also open portal accounts for them. */
return new class extends Migration
{
    protected const GRANTS = [
        'sales.clients.view' => ['portal.users.view'],
        'sales.clients.edit' => ['portal.users.create', 'portal.users.edit'],
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->syncPermissions();

        $all = collect(self::GRANTS)->flatten()->merge(array_keys(self::GRANTS))->unique()->values();
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $all)->pluck('id', 'name');

        foreach (self::GRANTS as $from => $targets) {
            foreach ($targets as $to) {
                if (! isset($ids[$from], $ids[$to])) {
                    continue;
                }
                $already = DB::table('role_has_permissions')->where('permission_id', $ids[$to])->pluck('role_id');
                DB::table('role_has_permissions')->where('permission_id', $ids[$from])->whereNotIn('role_id', $already)->pluck('role_id')
                    ->each(fn ($roleId) => DB::table('role_has_permissions')->insert(['permission_id' => $ids[$to], 'role_id' => $roleId]));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants stay.
    }
};

<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Roles that already edit company settings also get «دليل الترميز». */
return new class extends Migration
{
    protected const GRANTS = [
        'admin.settings.view' => 'admin.numbering.view',
        'admin.settings.edit' => 'admin.numbering.edit',
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->syncPermissions();

        $ids = DB::table('permissions')->where('guard_name', 'web')
            ->whereIn('name', [...array_keys(self::GRANTS), ...array_values(self::GRANTS)])
            ->pluck('id', 'name');

        foreach (self::GRANTS as $from => $to) {
            if (! isset($ids[$from], $ids[$to])) {
                continue;
            }
            $already = DB::table('role_has_permissions')->where('permission_id', $ids[$to])->pluck('role_id');
            DB::table('role_has_permissions')->where('permission_id', $ids[$from])->whereNotIn('role_id', $already)->pluck('role_id')
                ->each(fn ($roleId) => DB::table('role_has_permissions')->insert(['permission_id' => $ids[$to], 'role_id' => $roleId]));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants stay.
    }
};

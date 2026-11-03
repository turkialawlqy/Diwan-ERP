<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Existing roles get administrative-request permissions from what they already do in HR. */
return new class extends Migration
{
    protected const GRANTS = [
        'hr.leaves.view' => ['hr.admin_requests.view'],
        'hr.leaves.create' => ['hr.admin_requests.create', 'hr.admin_requests.edit'],
        'hr.leaves.approve' => ['hr.admin_requests.delete', 'hr.admin_requests.manage'],
        'hr.self_service.use' => ['hr.admin_requests.handle'],
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

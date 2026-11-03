<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Roles that already work in sales / work orders get the matching sector permissions. Medical data is NOT granted. */
return new class extends Migration
{
    protected const GRANTS = [
        'sales.invoices.view' => ['gym.attendance.view', 'real_estate.properties.view', 'real_estate.units.view', 'real_estate.leases.view', 'real_estate.reports.view'],
        'sales.invoices.create' => ['gym.checkin.use', 'real_estate.leases.create'],
        'sales.invoices.edit' => ['real_estate.properties.create', 'real_estate.properties.edit', 'real_estate.units.create', 'real_estate.units.edit', 'real_estate.leases.edit'],
        'work_orders.orders.view' => ['workshop.vehicles.view'],
        'work_orders.orders.create' => ['workshop.vehicles.create'],
        'work_orders.orders.edit' => ['workshop.vehicles.edit'],
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

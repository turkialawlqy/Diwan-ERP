<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Existing roles get work-order permissions from what they already do in sales / support. */
return new class extends Migration
{
    protected const GRANTS = [
        'sales.invoices.view' => ['work_orders.orders.view', 'work_orders.types.view', 'work_orders.timesheets.view'],
        'sales.invoices.create' => ['work_orders.orders.create', 'work_orders.orders.edit', 'work_orders.orders.invoice'],
        'sales.invoices.edit' => ['work_orders.types.create', 'work_orders.types.edit', 'work_orders.settings.view', 'work_orders.settings.edit'],
        'support.tickets.edit' => ['work_orders.orders.view', 'work_orders.orders.create', 'work_orders.orders.edit'],
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

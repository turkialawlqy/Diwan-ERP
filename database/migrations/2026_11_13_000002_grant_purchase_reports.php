<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * 1.7.2 adds «تقارير المشتريات». Roles that read purchase invoices or financial reports get it,
 * and those that could export sales / financial reports can export it too.
 */
return new class extends Migration
{
    protected const GRANTS = [
        ['purchases.invoices.view', 'reports.purchases.view'],
        ['reports.financial.view', 'reports.purchases.view'],
        ['reports.sales.export', 'reports.purchases.export'],
        ['reports.financial.export', 'reports.purchases.export'],
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->syncPermissions();

        $names = collect(self::GRANTS)->flatten()->unique()->all();
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $names)->pluck('id', 'name');

        foreach (self::GRANTS as [$from, $to]) {
            if (! isset($ids[$from], $ids[$to])) {
                continue;
            }
            $already = DB::table('role_has_permissions')->where('permission_id', $ids[$to])->pluck('role_id');
            DB::table('role_has_permissions')->where('permission_id', $ids[$from])
                ->whereNotIn('role_id', $already)->pluck('role_id')
                ->each(fn ($roleId) => DB::table('role_has_permissions')->insert(['permission_id' => $ids[$to], 'role_id' => $roleId]));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants stay: removing them could take away actions an owner gave on purpose.
    }
};

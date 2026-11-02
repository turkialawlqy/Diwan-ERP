<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * 1.6.0 added «تعديل» to expense / revenue vouchers and «حذف» to customer groups.
 * Roles that could already create vouchers (or edit customer groups) get the new
 * action, so the new buttons show without every owner editing each role.
 */
return new class extends Migration
{
    protected const GRANTS = [
        'finance.expenses.create' => 'finance.expenses.edit',
        'finance.revenues.create' => 'finance.revenues.edit',
        'sales.customer_groups.edit' => 'sales.customer_groups.delete',
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

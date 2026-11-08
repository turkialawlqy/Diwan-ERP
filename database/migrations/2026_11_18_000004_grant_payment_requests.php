<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * 1.7.2 adds «طلبات الصرف»: roles that record expenses may raise and edit requests, roles that edit expenses
 * (the approval permission of the expense type) may approve them.
 */
return new class extends Migration
{
    protected const GRANTS = [
        'finance.expenses.view' => ['finance.payment_requests.view'],
        'finance.expenses.create' => ['finance.payment_requests.create', 'finance.payment_requests.edit'],
        'finance.expenses.edit' => ['finance.payment_requests.approve'],
        'finance.expenses.delete' => ['finance.payment_requests.delete'],
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->syncPermissions();

        $names = [...array_keys(self::GRANTS), ...array_merge(...array_values(self::GRANTS))];
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $names)->pluck('id', 'name');
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
        // Grants stay: removing them could take away access an owner gave on purpose.
    }
};

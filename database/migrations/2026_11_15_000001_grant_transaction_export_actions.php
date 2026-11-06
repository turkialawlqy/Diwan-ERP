<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * 1.7.2 adds «تصدير» to purchase invoices, receipts, supplier payments, expenses and journals.
 * Roles that already export financial statements (the accounting roles) get the new actions;
 * everyone else is left to the owner, since an export takes the whole list out of the system.
 */
return new class extends Migration
{
    protected const NEW = ['purchases.invoices.export', 'sales.payments.export', 'purchases.payments.export', 'finance.expenses.export', 'accounting.journals.export'];

    public function up(): void
    {
        app(PermissionRegistry::class)->syncPermissions();

        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', [...self::NEW, 'accounting.statements.export'])->pluck('id', 'name');
        if (isset($ids['accounting.statements.export'])) {
            $roles = DB::table('role_has_permissions')->where('permission_id', $ids['accounting.statements.export'])->pluck('role_id');
            foreach (self::NEW as $name) {
                if (! isset($ids[$name])) {
                    continue;
                }
                $already = DB::table('role_has_permissions')->where('permission_id', $ids[$name])->pluck('role_id');
                foreach ($roles->diff($already) as $roleId) {
                    DB::table('role_has_permissions')->insert(['permission_id' => $ids[$name], 'role_id' => $roleId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants stay: removing them could take away actions an owner gave on purpose.
    }
};

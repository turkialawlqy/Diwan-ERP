<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** «كشف حساب مجمع» is given to every role that already opens the general ledger. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistry::class)->syncPermissions();

        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['accounting.ledger.view', 'accounting.ledger.consolidated'])->pluck('id', 'name');
        if (isset($ids['accounting.ledger.view'], $ids['accounting.ledger.consolidated'])) {
            $already = DB::table('role_has_permissions')->where('permission_id', $ids['accounting.ledger.consolidated'])->pluck('role_id');
            DB::table('role_has_permissions')->where('permission_id', $ids['accounting.ledger.view'])->whereNotIn('role_id', $already)->pluck('role_id')
                ->each(fn ($roleId) => DB::table('role_has_permissions')->insert(['permission_id' => $ids['accounting.ledger.consolidated'], 'role_id' => $roleId]));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants stay: removing them could take away access an owner gave on purpose.
    }
};

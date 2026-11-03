<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Existing roles get booking permissions from what they already do in sales. */
return new class extends Migration
{
    protected const GRANTS = [
        'sales.invoices.view' => ['bookings.bookings.view', 'bookings.resources.view', 'bookings.services.view', 'bookings.packages.view'],
        'sales.invoices.create' => ['bookings.bookings.create', 'bookings.bookings.edit', 'bookings.packages.create'],
        'sales.invoices.edit' => ['bookings.resources.create', 'bookings.resources.edit', 'bookings.services.create', 'bookings.services.edit', 'bookings.packages.edit', 'bookings.settings.view', 'bookings.settings.edit'],
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

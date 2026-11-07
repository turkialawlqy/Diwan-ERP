<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/** Files attached to documents (invoices, bills, vouchers, journals, quotations, orders) and who may use them. */
return new class extends Migration
{
    protected const GRANTS = [
        ['sales.invoices.view', 'admin.attachments.view'],
        ['purchases.invoices.view', 'admin.attachments.view'],
        ['finance.expenses.view', 'admin.attachments.view'],
        ['accounting.journals.view', 'admin.attachments.view'],
        ['sales.invoices.create', 'admin.attachments.create'],
        ['purchases.invoices.create', 'admin.attachments.create'],
        ['finance.expenses.create', 'admin.attachments.create'],
        ['accounting.journals.create', 'admin.attachments.create'],
        ['finance.expenses.edit', 'admin.attachments.delete'],
        ['accounting.journals.post', 'admin.attachments.delete'],
    ];

    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 100);
            $table->unsignedBigInteger('attachable_id');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'attachable_type', 'attachable_id']);
        });

        app(PermissionRegistry::class)->syncPermissions();
        $names = collect(self::GRANTS)->flatten()->unique()->all();
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $names)->pluck('id', 'name');
        foreach (self::GRANTS as [$from, $to]) {
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
        Schema::dropIfExists('attachments');
    }
};

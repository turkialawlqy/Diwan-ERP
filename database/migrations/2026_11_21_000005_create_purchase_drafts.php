<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * «إدخال مواد للمستودع»: the warehouse keeper adds items and quantities (the stock enters at once on a goods receipt with a
 * provisional cost) and a purchase draft is opened for accounting to complete — supplier, date and prices — which turns the
 * receipt into a supplier invoice. A receipt may therefore exist before its supplier is known.
 */
return new class extends Migration
{
    protected const GRANTS = [
        'inventory.operations.create' => ['inventory.stock_in.view', 'inventory.stock_in.create'],
        'purchases.invoices.view' => ['purchases.drafts.view'],
        'purchases.invoices.create' => ['purchases.drafts.view', 'purchases.drafts.complete', 'purchases.drafts.delete'],
    ];

    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->change();
        });

        Schema::create('purchase_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('status', 20)->default('pending'); // pending | completed | cancelled
            $table->text('notes')->nullable();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->unique('goods_receipt_id');
            $table->index(['company_id', 'status']);
        });

        app(PermissionRegistry::class)->syncPermissions();
        $names = collect(self::GRANTS)->flatMap(fn ($to, $from) => [$from, ...$to])->unique()->all();
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
        Schema::dropIfExists('purchase_drafts');
    }
};

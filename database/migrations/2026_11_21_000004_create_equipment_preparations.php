<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * «تحضير المعدات»: the daily preparation of each piece of equipment (checked before work starts), with the
 * preparation cards attached as files (one or several) through the attachments table.
 */
return new class extends Migration
{
    protected const GRANTS = [
        'equipment.maintenance.view' => ['equipment.preparations.view'],
        'equipment.readings.create' => ['equipment.preparations.create', 'equipment.preparations.edit'],
        'equipment.maintenance.delete' => ['equipment.preparations.delete'],
    ];

    public function up(): void
    {
        Schema::create('equipment_preparations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->date('prep_date');
            $table->string('operator', 120)->nullable();
            $table->decimal('hours_reading', 12, 2)->nullable();
            $table->decimal('km_reading', 12, 2)->nullable();
            $table->string('status', 20)->default('ready'); // ready | needs_attention
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'equipment_id', 'prep_date']);
            $table->index(['company_id', 'prep_date']);
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
        Schema::dropIfExists('equipment_preparations');
    }
};

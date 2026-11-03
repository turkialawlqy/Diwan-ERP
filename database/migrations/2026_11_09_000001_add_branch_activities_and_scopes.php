<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activities per branch (branches.modules; null = every company module) and a branch on the activity
 * records that had none, so a user linked to branch A never reaches branch B's data. Existing rows keep
 * a NULL branch (visible to every branch), so nothing disappears on upgrade.
 */
return new class extends Migration
{
    protected const TABLES = ['bookable_services', 'work_types', 'visit_packages', 'service_plans', 'customer_vehicles', 'properties', 'property_units', 'leases', 'patient_files', 'patient_visits', 'member_checkins'];

    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->json('modules')->nullable();
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->index('branch_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
        }
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn('modules'));
    }
};

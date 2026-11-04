<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A gym check-in admits only a plan marked «عضوية», not any subscription (rent, services...). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            $table->boolean('is_membership')->default(false);
        });
        // The gym pack's plans are memberships.
        DB::table('service_plans')->whereIn('name', ['عضوية شهرية', 'عضوية ربع سنوية', 'عضوية سنوية'])->update(['is_membership' => true]);
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            $table->dropColumn('is_membership');
        });
    }
};

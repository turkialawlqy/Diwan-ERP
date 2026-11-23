<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «حضور وانصراف المعدة»: the time a piece of equipment started and stopped working on a day, beside its meter readings. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('equipment_readings', 'check_in')) {
            Schema::table('equipment_readings', function (Blueprint $t) {
                $t->time('check_in')->nullable()->after('worked_hours');
                $t->time('check_out')->nullable()->after('check_in');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('equipment_readings', 'check_in')) {
            Schema::table('equipment_readings', fn (Blueprint $t) => $t->dropColumn(['check_in', 'check_out']));
        }
    }
};

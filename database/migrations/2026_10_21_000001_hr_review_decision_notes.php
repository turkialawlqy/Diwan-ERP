<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approver's note on a leave or loan decision (e.g. why it was
 * rejected), shown to the employee in self service.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['leave_requests', 'employee_loans'] as $table) {
            if (! Schema::hasColumn($table, 'decision_note')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('decision_note', 500)->nullable()->after('status'));
            }
        }
    }

    public function down(): void
    {
        foreach (['leave_requests', 'employee_loans'] as $table) {
            if (Schema::hasColumn($table, 'decision_note')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('decision_note'));
            }
        }
    }
};

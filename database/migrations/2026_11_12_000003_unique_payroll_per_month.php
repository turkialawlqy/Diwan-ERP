<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One payroll per company per month (the database says it too). Skipped, with the duplicates left for the owner to review, if some already exist. */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('payrolls')->select('company_id', 'year', 'month')->groupBy('company_id', 'year', 'month')->havingRaw('count(*) > 1')->exists();
        if ($duplicates) {
            return;
        }
        Schema::table('payrolls', function (Blueprint $table) {
            $table->unique(['company_id', 'year', 'month'], 'payrolls_company_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropUnique('payrolls_company_period_unique');
        });
    }
};

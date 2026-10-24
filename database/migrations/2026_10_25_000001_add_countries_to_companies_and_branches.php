<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Countries per branch: each branch operates under its country's tax,
 * currency and e-invoicing rules (config/countries.php). Existing data is
 * Saudi, so every company and branch starts as SA and behaves as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->char('country_code', 2)->default('SA')->after('currency');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable()->after('city'); // null: the company's country
            $table->string('currency', 3)->nullable()->after('country_code'); // null: the company's base currency
            $table->string('tax_number', 30)->nullable()->after('currency'); // null: the company's
            $table->boolean('tax_registered')->default(true)->after('tax_number');
            $table->string('commercial_registration', 30)->nullable()->after('tax_registered');
            $table->string('timezone', 40)->nullable()->after('commercial_registration');
        });

        DB::table('companies')->whereNull('country_code')->update(['country_code' => 'SA']);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['country_code', 'currency', 'tax_number', 'tax_registered', 'commercial_registration', 'timezone']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};

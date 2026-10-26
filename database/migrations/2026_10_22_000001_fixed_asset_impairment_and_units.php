<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impairment losses on fixed assets (IAS 36) and depreciation by units of
 * production: the expected lifetime units of an asset and its usage per
 * month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->decimal('accumulated_impairment', 18, 2)->default(0)->after('accumulated_depreciation');
            $table->decimal('total_units', 18, 2)->nullable()->after('useful_life_months');
            $table->string('unit_label', 20)->nullable()->after('total_units');
        });

        Schema::create('asset_impairments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 2);
            $table->string('reason', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('asset_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('period'); // Ym
            $table->decimal('units', 18, 2);
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_usages');
        Schema::dropIfExists('asset_impairments');
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn(['accumulated_impairment', 'total_units', 'unit_label']);
        });
    }
};

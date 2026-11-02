<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «دليل الترميز»: prefix / digits / start number per document type, optionally
 * from a given year on (valid_from_year 0 = the base rule). Existing
 * "{type}_prefix" company settings become base rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->unsignedSmallInteger('valid_from_year')->default(0);
            $table->string('prefix', 10);
            $table->unsignedTinyInteger('pad')->default(6);
            $table->unsignedBigInteger('start_number')->default(1);
            $table->boolean('with_year')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'type', 'valid_from_year']);
        });

        $types = config('documents.types');
        DB::table('company_settings')->where('key', 'like', '%\_prefix')->orderBy('id')->each(function ($setting) use ($types) {
            $type = substr($setting->key, 0, -7);
            $prefix = trim((string) $setting->value);
            if (! isset($types[$type]) || $prefix === '' || strlen($prefix) > 10) {
                return;
            }
            DB::table('numbering_rules')->insertOrIgnore([
                'company_id' => $setting->company_id, 'type' => $type, 'valid_from_year' => 0, 'prefix' => $prefix,
                'pad' => $types[$type]['pad'], 'start_number' => 1, 'with_year' => $types[$type]['yearly'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('company_settings')->where('id', $setting->id)->delete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Suppliers get a numbered code (S-00001) like customers (C-00001). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('company_id');
        });

        $next = [];
        DB::table('suppliers')->orderBy('id')->each(function ($supplier) use (&$next) {
            $next[$supplier->company_id] = ($next[$supplier->company_id] ?? 0) + 1;
            DB::table('suppliers')->where('id', $supplier->id)->update(['code' => 'S-'.str_pad((string) $next[$supplier->company_id], 5, '0', STR_PAD_LEFT)]);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
            $table->dropColumn('code');
        });
    }
};

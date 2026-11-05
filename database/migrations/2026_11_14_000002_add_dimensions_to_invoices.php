<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cost centre and project on sales and purchase invoices: their revenue / cost lines carry them into the ledger. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'purchase_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('cost_center_id')->nullable();
                $t->unsignedBigInteger('project_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'purchase_invoices'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['cost_center_id', 'project_id']));
        }
    }
};

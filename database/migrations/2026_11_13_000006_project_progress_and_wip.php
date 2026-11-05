<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Executed quantity per BOQ line (work done, whether certified or not) and the WIP adjustments posted from it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_boq_items', function (Blueprint $table) {
            $table->decimal('executed_quantity', 18, 4)->default(0);
        });

        Schema::create('project_wip_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable();
            $table->date('date');
            $table->decimal('earned', 15, 2)->default(0);   // revenue earned by progress to the date
            $table->decimal('billed', 15, 2)->default(0);   // approved billings to the date
            $table->decimal('amount', 15, 2);                // earned - billed: + contract asset, - contract liability
            $table->string('status', 20)->default('posted'); // posted, reversed
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_wip_entries');
        Schema::table('project_boq_items', fn (Blueprint $t) => $t->dropColumn('executed_quantity'));
    }
};

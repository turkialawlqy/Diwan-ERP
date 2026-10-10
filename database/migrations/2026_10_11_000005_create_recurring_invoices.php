<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contracts billed on a schedule (rent, maintenance, subscriptions).
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->json('lines');
            $table->string('frequency', 10); // monthly, quarterly, yearly
            $table->unsignedTinyInteger('interval')->default(1);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_count')->nullable();
            $table->unsignedInteger('generated_count')->default(0);
            $table->date('next_run_date');
            $table->string('currency_code', 3)->nullable();
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->string('invoice_type', 12)->default('standard');
            $table->boolean('auto_send')->default(false);
            $table->string('send_to')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 10)->default('active'); // active, paused, ended
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'next_run_date']);
        });

        // One row per issued period: the unique key makes generation idempotent.
        Schema::create('recurring_invoice_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_invoice_id')->constrained()->cascadeOnDelete();
            $table->date('period_date');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['recurring_invoice_id', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_runs');
        Schema::dropIfExists('recurring_invoices');
    }
};

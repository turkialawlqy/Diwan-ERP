<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer subscriptions: a catalogue of service plans and each customer's
 * subscription. Billing itself is done by a recurring invoice template the
 * subscription owns (see App\Services\Subscriptions\SubscriptionService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 14, 2);
            $table->decimal('tax_rate', 5, 2)->default(15);
            $table->string('cycle', 10)->default('monthly'); // monthly, quarterly, yearly
            $table->unsignedTinyInteger('interval')->default(1);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('customer_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_plan_id')->constrained('service_plans');
            $table->foreignId('pending_plan_id')->nullable()->constrained('service_plans')->nullOnDelete(); // change at next renewal
            $table->foreignId('recurring_invoice_id')->nullable()->constrained('recurring_invoices')->nullOnDelete();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('tax_rate', 5, 2)->default(15);
            $table->string('status', 10)->default('active'); // trial, active, paused, past_due, cancelled, expired
            $table->date('start_date');
            $table->date('trial_ends_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('cancel_at_period_end')->default(false);
            $table->date('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('reminder_state', 20)->nullable(); // last renewal reminder step sent
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_subscriptions');
        Schema::dropIfExists('service_plans');
    }
};

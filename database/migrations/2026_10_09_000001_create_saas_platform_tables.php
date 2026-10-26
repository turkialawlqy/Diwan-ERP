<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS platform: plan modules and limits, subscription billing, verified
 * custom domains, light CRM, outgoing webhooks, in-app notifications and
 * company data exports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->json('modules')->nullable(); // null = every module
            $table->integer('max_warehouses')->default(1);
            $table->integer('max_employees')->default(10);
            $table->integer('trial_days')->default(14);
            $table->integer('sort')->default(0);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('subscription_ends_at')->nullable();
            $table->string('billing_cycle', 10)->default('monthly'); // monthly, yearly
            $table->string('suspended_reason', 500)->nullable();
            $table->string('custom_domain_token', 64)->nullable();
            $table->timestamp('custom_domain_verified_at')->nullable();
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained();
            $table->string('number', 30)->unique();
            $table->string('billing_cycle', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 12, 2);
            $table->decimal('vat_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('status', 20)->default('unpaid'); // unpaid, paid, void
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50); // impersonation_started, impersonation_ended, plan_changed, suspended, activated, ...
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); // after conversion
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('source', 30)->nullable(); // website, referral, call, exhibition, social, other
            $table->string('status', 20)->default('new'); // new, contacted, qualified, proposal, won, lost
            $table->decimal('expected_value', 14, 2)->default(0);
            $table->date('next_follow_up')->nullable();
            $table->string('lost_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'next_follow_up']);
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // call, meeting, email, whatsapp, note
            $table->text('notes');
            $table->timestamp('happened_at');
            $table->timestamps();
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->json('events');
            $table->text('secret'); // encrypted
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivery_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->timestamps();
        });

        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued, ready, failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['data_exports', 'webhook_endpoints', 'lead_activities', 'leads', 'platform_audit_logs', 'subscription_invoices'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['subscription_ends_at', 'billing_cycle', 'suspended_reason', 'custom_domain_token', 'custom_domain_verified_at']));
        Schema::table('subscription_plans', fn (Blueprint $t) => $t->dropColumn(['modules', 'max_warehouses', 'max_employees', 'trial_days', 'sort']));
    }
};

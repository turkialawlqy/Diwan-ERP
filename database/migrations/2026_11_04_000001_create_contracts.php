<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Commercial contracts: text with versions, approval, renewal chain and obligations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 10)->default('service'); // sales, purchase, service, lease, other
            $table->longText('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->string('title');
            $table->string('kind', 10)->default('service');
            $table->string('party_type', 10)->default('customer'); // customer, supplier
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('contract_templates')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('customer_subscriptions')->nullOnDelete();
            $table->string('status', 16)->default('draft'); // draft, pending_approval, rejected, approved, active, expired, terminated, renewed
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('value', 16, 2)->default(0);
            $table->text('payment_terms')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('renewal_term_months')->nullable();
            $table->unsignedSmallInteger('renewal_notice_days')->default(30);
            $table->longText('body')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('parent_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->date('signed_at')->nullable();
            $table->date('terminated_at')->nullable();
            $table->string('termination_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('attachments')->nullable();
            $table->string('reminder_state', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'end_date']);
        });

        Schema::create('contract_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->longText('body')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['contract_id', 'version']);
        });

        Schema::create('contract_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 12)->default('payment'); // payment, delivery, deliverable, milestone, other
            $table->date('due_date');
            $table->decimal('amount', 16, 2)->nullable();
            $table->string('status', 8)->default('pending'); // pending, done
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->string('reminder_state', 20)->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'status']);
            $table->index(['company_id', 'due_date']);
        });

        // Plans that sell sales or purchases also get the contracts module (null modules = every module already).
        foreach (DB::table('subscription_plans')->whereNotNull('modules')->get(['id', 'modules']) as $plan) {
            $modules = json_decode($plan->modules, true) ?: [];
            if ((in_array('sales', $modules, true) || in_array('purchases', $modules, true)) && ! in_array('contracts', $modules, true)) {
                $modules[] = 'contracts';
                DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode($modules)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_obligations');
        Schema::dropIfExists('contract_versions');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('contract_templates');
    }
};

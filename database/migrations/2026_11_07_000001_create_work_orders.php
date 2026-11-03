<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Field-service work orders: work types, orders with crew / tasks / parts / photos, and time entries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('hourly_rate', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('min_billable_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('labour_cost_rate', 12, 4)->nullable();
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('customer_subscriptions')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('site_address')->nullable();
            $table->string('site_phone', 40)->nullable();
            $table->string('priority', 10)->default('normal');
            $table->string('status', 12)->default('new'); // new, scheduled, in_progress, on_hold, done, invoiced, cancelled
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('labour_amount', 14, 2)->default(0);
            $table->decimal('parts_amount', 14, 2)->default(0);
            $table->decimal('cost_amount', 14, 2)->default(0);
            $table->string('customer_signature_path')->nullable();
            $table->string('signed_by')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->json('reminder_state')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'assigned_to', 'scheduled_start']);
            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('work_order_technicians', function (Blueprint $table) {
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->primary(['work_order_id', 'employee_id']);
        });

        Schema::create('work_order_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->boolean('billable')->default(true);
            $table->string('source', 8)->default('timer'); // timer, manual
            $table->decimal('labour_cost', 14, 2)->default(0);
            $table->boolean('auto_closed')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'employee_id', 'started_at']);
            $table->index(['work_order_id']);
        });

        Schema::create('work_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->boolean('returned')->default(false);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('work_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name');
            $table->string('kind', 10)->default('photo'); // photo, document
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (DB::table('subscription_plans')->whereNotNull('modules')->get(['id', 'modules']) as $plan) {
            $modules = json_decode($plan->modules, true) ?: [];
            if (in_array('sales', $modules, true) && ! in_array('work_orders', $modules, true)) {
                $modules[] = 'work_orders';
                DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode($modules)]);
            }
        }
    }

    public function down(): void
    {
        foreach (['work_order_attachments', 'work_order_parts', 'time_entries', 'work_order_tasks', 'work_order_technicians', 'work_orders'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('labour_cost_rate'));
        Schema::dropIfExists('work_types');
    }
};

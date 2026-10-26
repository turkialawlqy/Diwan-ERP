<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A first run on MySQL could stop half-way (DDL is not transactional),
        // leaving some of these tables behind while the migration stays
        // unrecorded. They hold no data then, so start clean.
        Schema::disableForeignKeyConstraints();
        foreach (['maintenance_order_schedules', 'maintenance_order_items', 'maintenance_orders', 'equipment_billings', 'equipment_readings',
            'equipment_rentals', 'equipment_maintenance_schedules', 'equipment'] as $leftover) {
            Schema::dropIfExists($leftover);
        }
        Schema::enableForeignKeyConstraints();

        // Index names are explicit: MySQL limits identifiers to 64 characters.
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('category', 60)->nullable(); // excavator, crane, truck...
            $table->string('model', 100)->nullable();
            $table->string('plate_number', 30)->nullable();
            $table->string('serial_number', 60)->nullable();
            $table->string('ownership', 12)->default('owned'); // owned, rented_in
            $table->foreignId('fixed_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('supplier_rate', 14, 2)->default(0);
            $table->string('supplier_rate_basis', 10)->nullable(); // hourly, daily, monthly
            $table->string('meter_type', 6)->default('hours'); // hours, km, both, none
            $table->decimal('current_hours', 12, 1)->default(0);
            $table->decimal('current_km', 12, 1)->default(0);
            $table->date('last_reading_date')->nullable();
            $table->decimal('hourly_rate', 14, 2)->default(0);
            $table->decimal('daily_rate', 14, 2)->default(0);
            $table->decimal('monthly_rate', 14, 2)->default(0);
            $table->decimal('internal_hourly_cost', 14, 2)->default(0);
            $table->string('status', 16)->default('available'); // available, rented, internal, maintenance, out_of_service
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'eq_company_code_unique');
            $table->index(['company_id', 'status'], 'eq_company_status_idx');
        });

        // Periodic maintenance per item: due when ANY interval is reached.
        Schema::create('equipment_maintenance_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('default_quantity', 12, 3)->default(1);
            $table->decimal('interval_hours', 10, 1)->nullable();
            $table->decimal('interval_km', 10, 1)->nullable();
            $table->unsignedInteger('interval_days')->nullable();
            $table->decimal('warn_hours', 10, 1)->nullable();
            $table->decimal('warn_km', 10, 1)->nullable();
            $table->unsignedInteger('warn_days')->nullable();
            $table->date('last_done_date')->nullable();
            $table->decimal('last_done_hours', 12, 1)->nullable();
            $table->decimal('last_done_km', 12, 1)->nullable();
            $table->string('alert_state', 10)->default('ok'); // ok, due_soon, overdue
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'is_active', 'alert_state'], 'eq_sched_company_active_state_idx');
        });

        Schema::create('equipment_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('type', 10); // customer, project
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billing_basis', 10); // hourly, daily, monthly
            $table->decimal('rate', 14, 2);
            $table->decimal('min_hours_per_day', 5, 2)->nullable();
            $table->boolean('with_operator')->default(false);
            $table->decimal('tax_rate', 5, 2)->default(15);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('billed_until')->nullable();
            $table->string('site', 191)->nullable();
            $table->string('status', 10)->default('active'); // active, closed
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number'], 'eq_rentals_company_number_unique');
            $table->index(['company_id', 'status'], 'eq_rentals_company_status_idx');
        });

        Schema::create('equipment_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained('equipment_rentals')->nullOnDelete();
            $table->date('date');
            $table->decimal('hours_reading', 12, 1)->nullable();
            $table->decimal('km_reading', 12, 1)->nullable();
            $table->decimal('worked_hours', 6, 2)->default(0);
            $table->string('operator', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['equipment_id', 'date'], 'eq_readings_equipment_date_unique');
            $table->index(['company_id', 'date'], 'eq_readings_company_date_idx');
        });

        // Billed periods: customer invoices / project charges (out) and supplier rent (in).
        Schema::create('equipment_billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // out, in
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained('equipment_rentals')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 10); // hourly, daily, monthly
            $table->decimal('rate', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_cost_id')->nullable()->constrained('project_costs')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'direction', 'period_from'], 'eq_billings_company_dir_from_idx');
            $table->index(['equipment_id', 'period_from'], 'eq_billings_equipment_from_idx');
        });

        Schema::create('maintenance_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('type', 12)->default('preventive'); // preventive, corrective
            $table->date('date');
            $table->decimal('hours_reading', 12, 1)->nullable();
            $table->decimal('km_reading', 12, 1)->nullable();
            $table->string('technician', 100)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('open'); // open, completed
            $table->string('previous_status', 16)->nullable();
            $table->decimal('parts_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number'], 'mo_company_number_unique');
            $table->index(['company_id', 'status'], 'mo_company_status_idx');
        });

        Schema::create('maintenance_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('equipment_maintenance_schedules')->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Which periodic items were serviced by an order.
        Schema::create('maintenance_order_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('equipment_maintenance_schedules')->cascadeOnDelete();
            $table->boolean('was_overdue')->default(false);
            $table->unique(['maintenance_order_id', 'schedule_id'], 'mo_sched_order_schedule_unique');
        });

        // Plans that sell contracting also get the equipment module.
        foreach (DB::table('subscription_plans')->whereNotNull('modules')->get(['id', 'modules']) as $plan) {
            $modules = json_decode($plan->modules, true) ?: [];
            if (in_array('projects', $modules, true) && ! in_array('equipment', $modules, true)) {
                $modules[] = 'equipment';
                DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode($modules)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_order_schedules');
        Schema::dropIfExists('maintenance_order_items');
        Schema::dropIfExists('maintenance_orders');
        Schema::dropIfExists('equipment_billings');
        Schema::dropIfExists('equipment_readings');
        Schema::dropIfExists('equipment_rentals');
        Schema::dropIfExists('equipment_maintenance_schedules');
        Schema::dropIfExists('equipment');
    }
};

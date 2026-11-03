<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Light sector tables: patient files, gym check-ins, customer vehicles, properties / units / leases. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('file_number', 30);
            $table->date('birth_date')->nullable();
            $table->string('gender', 8)->nullable();
            $table->string('blood_type', 4)->nullable();
            $table->text('allergies')->nullable();
            $table->text('chronic_conditions')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'file_number']);
            $table->unique(['company_id', 'customer_id']);
        });

        Schema::create('patient_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('visit_date');
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment')->nullable();
            $table->text('prescription')->nullable();
            $table->date('next_visit_date')->nullable();
            $table->json('attachments')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['patient_file_id', 'visit_date']);
        });

        Schema::create('member_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('customer_subscriptions')->nullOnDelete();
            $table->foreignId('customer_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code_entered', 60)->nullable();
            $table->string('method', 8)->default('scan'); // scan, manual
            $table->boolean('allowed')->default(false);
            $table->string('reason')->nullable();
            $table->dateTime('checked_in_at');
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'checked_in_at']);
        });

        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('plate', 30);
            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('vin', 40)->nullable();
            $table->string('color', 30)->nullable();
            $table->unsignedInteger('odometer')->default(0);
            $table->date('next_service_date')->nullable();
            $table->unsignedInteger('next_service_km')->nullable();
            $table->json('reminder_state')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'next_service_date']);
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('building'); // building, villa, complex, land, shop
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('property_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('unit_number', 30);
            $table->string('type', 20)->default('apartment'); // apartment, shop, office, warehouse, room
            $table->string('floor', 10)->nullable();
            $table->decimal('area', 10, 2)->nullable();
            $table->decimal('rent_amount', 14, 2)->default(0);
            $table->string('status', 12)->default('vacant'); // vacant, occupied, maintenance
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'unit_number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('property_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('customer_subscriptions')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('rent_amount', 14, 2);
            $table->string('cycle', 10)->default('monthly'); // monthly, quarterly, yearly
            $table->decimal('security_deposit', 14, 2)->default(0);
            $table->string('status', 10)->default('active'); // active, ended
            $table->date('ended_on')->nullable();
            $table->string('end_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'end_date']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('equipment_id')->constrained('customer_vehicles')->nullOnDelete();
            $table->unsignedInteger('odometer_in')->nullable()->after('vehicle_id');
            $table->foreignId('property_unit_id')->nullable()->after('odometer_in')->constrained('property_units')->nullOnDelete();
        });
        Schema::table('work_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('service_interval_days')->nullable();
            $table->unsignedInteger('service_interval_km')->nullable();
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('property_unit_id')->nullable()->constrained('property_units')->nullOnDelete();
        });

        foreach (DB::table('subscription_plans')->whereNotNull('modules')->get(['id', 'modules']) as $plan) {
            $modules = json_decode($plan->modules, true) ?: [];
            if (in_array('sales', $modules, true)) {
                $changed = false;
                foreach (['clinic', 'gym', 'workshop', 'real_estate'] as $m) {
                    if (! in_array($m, $modules, true)) {
                        $modules[] = $m;
                        $changed = true;
                    }
                }
                if ($changed) {
                    DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode($modules)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $t) => $t->dropConstrainedForeignId('property_unit_id'));
        Schema::table('work_types', fn (Blueprint $t) => $t->dropColumn(['service_interval_days', 'service_interval_km']));
        Schema::table('work_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('property_unit_id');
            $t->dropColumn('odometer_in');
            $t->dropConstrainedForeignId('vehicle_id');
        });
        foreach (['leases', 'property_units', 'properties', 'customer_vehicles', 'member_checkins', 'patient_visits', 'patient_files'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

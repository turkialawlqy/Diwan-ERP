<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bookings & appointments: resources with weekly hours, bookable services, bookings, visit packages. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('staff'); // staff, room, equipment, court
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('resource_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_resource_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO 1 (Mon) .. 7 (Sun)
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->index(['booking_resource_id', 'weekday']);
        });

        Schema::create('resource_time_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_resource_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->index(['booking_resource_id', 'starts_at']);
        });

        Schema::create('bookable_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('deposit_percent')->default(0);
            $table->boolean('online_bookable')->default(true);
            $table->unsignedSmallInteger('free_cancel_hours')->default(24);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bookable_service_resource', function (Blueprint $table) {
            $table->foreignId('bookable_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_resource_id')->constrained()->cascadeOnDelete();
            $table->primary(['bookable_service_id', 'booking_resource_id']);
        });

        Schema::create('visit_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('bookable_service_id')->nullable()->constrained()->nullOnDelete(); // null = any service
            $table->unsignedSmallInteger('visits');
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('valid_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customer_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bookable_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('visits_total');
            $table->unsignedSmallInteger('visits_used')->default(0);
            $table->date('expires_at')->nullable();
            $table->string('status', 12)->default('active'); // active, used_up, expired
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'customer_id', 'status']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bookable_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_resource_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 12)->default('pending'); // pending, confirmed, completed, cancelled, no_show
            $table->string('source', 8)->default('staff'); // staff, portal
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('member_discount', 14, 2)->default(0);
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('reminder_state')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['booking_resource_id', 'starts_at']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'status', 'starts_at']);
        });

        Schema::create('package_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('delta'); // -1 used, +1 given back
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('service_plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('booking_discount_percent')->default(0)->after('trial_days');
        });

        // Plans that sell sales also get the bookings module (null modules = every module already).
        foreach (DB::table('subscription_plans')->whereNotNull('modules')->get(['id', 'modules']) as $plan) {
            $modules = json_decode($plan->modules, true) ?: [];
            if (in_array('sales', $modules, true) && ! in_array('bookings', $modules, true)) {
                $modules[] = 'bookings';
                DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode($modules)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('service_plans', fn (Blueprint $t) => $t->dropColumn('booking_discount_percent'));
        foreach (['package_usages', 'bookings', 'customer_packages', 'visit_packages', 'bookable_service_resource', 'bookable_services', 'resource_time_offs', 'resource_hours', 'booking_resources'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

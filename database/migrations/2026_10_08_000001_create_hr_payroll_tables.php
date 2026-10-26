<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR & payroll under the Saudi Labour Law: organisation, employee file and
 * documents, shifts and attendance, leave, loans, monthly adjustments,
 * payroll with GOSI, and end-of-service settlements.
 */
return new class extends Migration
{
    public function up(): void
    {
        $money = fn (Blueprint $t, string $name) => $t->decimal($name, 14, 2)->default(0);

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('manager_employee_id')->nullable();
            $table->timestamps();
        });

        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('grade', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->json('work_days'); // ISO weekday numbers (1 = Monday ... 7 = Sunday)
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Employee master data (status was an enum; widen and extend).
        Schema::table('employees', function (Blueprint $table) {
            $table->string('status_new', 20)->default('active');
        });
        DB::table('employees')->update(['status_new' => DB::raw('status')]);
        Schema::table('employees', fn (Blueprint $t) => $t->dropIndex('employees_company_status_idx'));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('status'));
        Schema::table('employees', fn (Blueprint $t) => $t->renameColumn('status_new', 'status'));
        Schema::table('employees', fn (Blueprint $t) => $t->index(['company_id', 'status'], 'employees_company_status_idx'));

        Schema::table('employees', function (Blueprint $table) use ($money) {
            $table->string('employee_number', 30)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('job_positions')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // self service
            $table->foreignId('shift_id')->nullable()->constrained('work_shifts')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nationality', 2)->default('SA');
            $table->string('gender', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->string('id_type', 20)->default('national_id'); // national_id, iqama, border_number
            $table->date('id_expiry')->nullable();
            $table->string('passport_number', 30)->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('profession')->nullable(); // as registered with MHRSD / iqama
            $table->string('contract_type', 20)->default('unlimited'); // limited, unlimited
            $table->date('contract_end')->nullable();
            $table->date('probation_end')->nullable();
            $table->string('gosi_number', 30)->nullable();
            $table->boolean('gosi_registered')->default(true);
            $table->date('gosi_join_date')->nullable(); // new-entrant annuity rates since July 2024
            $table->unsignedSmallInteger('annual_leave_days')->default(21);
            $table->decimal('leave_opening_balance', 8, 2)->default(0);
            $money($table, 'eos_opening_provision');
            $table->date('termination_date')->nullable();
            $table->string('termination_reason', 30)->nullable();
            $table->string('bank_code', 10)->nullable();

            $table->unique(['company_id', 'employee_number']);
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // iqama, passport, contract, medical_insurance, driving_license, certificate, other
            $table->string('number', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('file_path')->nullable(); // private disk
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'expiry_date']);
        });

        Schema::create('employee_pay_items', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // allowance, deduction
            $table->string('name');
            $money($table, 'amount');
            $table->boolean('gosi_applicable')->default(false);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->string('status', 20)->default('present'); // present, absent, leave, holiday, weekend
            $table->string('source', 20)->default('manual'); // manual, import, device
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'date']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->unsignedSmallInteger('days_per_year')->default(0); // 0 = per occurrence / unlimited
            $table->string('pay_rule', 20)->default('full'); // full, sick_tiers, unpaid
            $table->boolean('deducts_balance')->default(false); // annual leave
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 6, 2);
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, cancelled
            $table->string('reason', 1000)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status', 'start_date']);
        });

        Schema::create('employee_loans', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $money($table, 'amount');
            $money($table, 'monthly_installment');
            $money($table, 'repaid');
            $table->date('first_deduction_month');
            $table->string('status', 20)->default('pending'); // pending, active, settled, rejected
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employee_adjustments', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('type', 20); // bonus, commission, overtime_hours, penalty, deduction
            $money($table, 'amount'); // hours for overtime_hours
            $table->string('description', 500)->nullable();
            $table->foreignId('payroll_id')->nullable(); // set when consumed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'year', 'month']);
        });

        Schema::table('payrolls', function (Blueprint $table) use ($money) {
            $table->string('status_new', 20)->default('draft');
            $money($table, 'total_overtime');
            $money($table, 'total_gosi_employee');
            $money($table, 'total_gosi_employer');
            $money($table, 'total_loans');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
        });
        DB::table('payrolls')->update(['status_new' => DB::raw('status')]);
        Schema::table('payrolls', fn (Blueprint $t) => $t->dropColumn('status'));
        Schema::table('payrolls', fn (Blueprint $t) => $t->renameColumn('status_new', 'status'));

        Schema::table('payroll_items', function (Blueprint $table) use ($money) {
            $table->unsignedTinyInteger('days_worked')->default(30);
            $money($table, 'housing_allowance');
            $money($table, 'transport_allowance');
            $money($table, 'other_allowances');
            $money($table, 'overtime');
            $money($table, 'bonuses');
            $money($table, 'absence_deduction');
            $money($table, 'loan_deduction');
            $money($table, 'other_deductions');
            $money($table, 'gosi_employee');
            $money($table, 'gosi_employer');
            $money($table, 'gross_salary');
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->json('breakdown')->nullable();
        });

        Schema::create('end_of_service_settlements', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('termination_date');
            $table->string('reason', 30); // resignation, termination, contract_end, article_80, retirement, death
            $table->decimal('service_years', 8, 4);
            $money($table, 'last_wage');
            $money($table, 'eos_full'); // before resignation reduction
            $money($table, 'eos_amount');
            $table->decimal('leave_days', 8, 2)->default(0);
            $money($table, 'leave_encashment');
            $money($table, 'salary_due');
            $money($table, 'other_dues');
            $money($table, 'loan_deduction');
            $money($table, 'other_deductions');
            $money($table, 'net_amount');
            $table->string('status', 20)->default('draft'); // draft, approved, paid
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['end_of_service_settlements', 'employee_adjustments', 'employee_loans', 'leave_requests', 'leave_types', 'attendance_records',
            'employee_pay_items', 'employee_documents'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('payroll_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('cost_center_id'));
        Schema::table('payroll_items', fn (Blueprint $t) => $t->dropColumn(['days_worked', 'housing_allowance', 'transport_allowance', 'other_allowances', 'overtime',
            'bonuses', 'absence_deduction', 'loan_deduction', 'other_deductions', 'gosi_employee', 'gosi_employer', 'gross_salary', 'breakdown']));
        Schema::table('payrolls', fn (Blueprint $t) => $t->dropConstrainedForeignId('approved_by'));
        Schema::table('payrolls', fn (Blueprint $t) => $t->dropColumn(['total_overtime', 'total_gosi_employee', 'total_gosi_employer', 'total_loans', 'approved_at', 'paid_at']));
        Schema::table('employees', function (Blueprint $t) {
            foreach (['department_id', 'position_id', 'manager_id', 'user_id', 'shift_id', 'cost_center_id'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
        });
        Schema::table('employees', fn (Blueprint $t) => $t->dropUnique(['company_id', 'employee_number']));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['employee_number', 'nationality', 'gender', 'birth_date', 'marital_status', 'id_type', 'id_expiry',
            'passport_number', 'passport_expiry', 'profession', 'contract_type', 'contract_end', 'probation_end', 'gosi_number', 'gosi_registered', 'gosi_join_date',
            'annual_leave_days', 'leave_opening_balance', 'eos_opening_provision', 'termination_date', 'termination_reason', 'bank_code']));
        Schema::dropIfExists('work_shifts');
        Schema::dropIfExists('job_positions');
        Schema::dropIfExists('departments');
    }
};

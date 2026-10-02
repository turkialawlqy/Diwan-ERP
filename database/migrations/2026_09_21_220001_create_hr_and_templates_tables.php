<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. الموظفين (Employees)
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('national_id', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('job_title');
            $table->string('department')->default('عام');
            $table->date('hire_date')->nullable();
            $table->decimal('basic_salary', 12, 2)->default(0.00);
            $table->decimal('housing_allowance', 12, 2)->default(0.00);
            $table->decimal('transport_allowance', 12, 2)->default(0.00);
            $table->decimal('other_allowances', 12, 2)->default(0.00);
            $table->decimal('total_salary', 12, 2)->default(0.00);
            $table->string('bank_name')->nullable();
            $table->string('iban', 50)->nullable();
            $table->enum('status', ['active', 'on_leave', 'terminated'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 2. مسيرات الرواتب (Payrolls)
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('payroll_number', 50);
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->decimal('total_basic', 15, 2)->default(0.00);
            $table->decimal('total_allowances', 15, 2)->default(0.00);
            $table->decimal('total_deductions', 15, 2)->default(0.00);
            $table->decimal('net_total', 15, 2)->default(0.00);
            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->date('payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'payroll_number']);
        });

        // 3. بنود مسير الرواتب (Payroll Items)
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('basic_salary', 12, 2)->default(0.00);
            $table->decimal('allowances', 12, 2)->default(0.00);
            $table->decimal('deductions', 12, 2)->default(0.00);
            $table->decimal('net_salary', 12, 2)->default(0.00);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // 4. قوالب الطباعة (Print Templates Settings)
        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('template_type', 50)->default('invoice_a4'); // invoice_a4, invoice_thermal_80mm, quotation, voucher
            $table->string('name');
            $table->string('primary_color', 20)->default('#4F46E5');
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_qr')->default(true);
            $table->boolean('show_stamp')->default(true);
            $table->boolean('show_bank_details')->default(true);
            $table->text('terms_and_conditions')->nullable();
            $table->text('footer_text')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_templates');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('employees');
    }
};

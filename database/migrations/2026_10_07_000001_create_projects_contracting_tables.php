<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracting & projects: contracts with BOQ / WBS, variation orders,
 * client progress billings (with advance recovery and retention),
 * subcontracts and their certificates, project costs (materials, labour,
 * equipment, other), letters of guarantee and site daily reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        $money = fn (Blueprint $t, string $name) => $t->decimal($name, 18, 2)->default(0);
        $qty = fn (Blueprint $t, string $name) => $t->decimal($name, 18, 4)->default(0);

        Schema::create('projects', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete(); // site store
            $table->string('code', 30);
            $table->string('name');
            $table->string('contract_number', 100)->nullable();
            $table->string('status', 20)->default('planning'); // planning, active, on_hold, completed, closed
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $money($table, 'contract_value'); // before VAT
            $table->decimal('advance_percent', 5, 2)->default(0);
            $table->decimal('retention_percent', 5, 2)->default(0);
            $money($table, 'advance_received'); // net of VAT, recovered through billings
            $money($table, 'advance_recovered');
            $money($table, 'retention_held');
            $money($table, 'retention_released');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('project_boq_items', function (Blueprint $table) use ($qty) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('project_boq_items')->nullOnDelete(); // WBS
            $table->foreignId('variation_id')->nullable(); // added by a variation order
            $table->string('code', 50);
            $table->string('description', 1000);
            $table->string('unit', 30)->nullable();
            $qty($table, 'quantity');
            $table->decimal('unit_price', 18, 4)->default(0); // selling rate
            $table->decimal('unit_cost', 18, 4)->default(0); // estimated cost rate
            $qty($table, 'billed_quantity'); // cumulative certified
            $table->boolean('is_group')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'parent_id']);
        });

        Schema::create('project_variations', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->date('date');
            $table->string('description', 1000);
            $money($table, 'amount'); // + addition / - omission, before VAT
            $money($table, 'cost_impact');
            $table->unsignedSmallInteger('time_impact_days')->default(0);
            $table->string('status', 20)->default('draft'); // draft, approved, rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
        });

        Schema::create('progress_billings', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->unsignedSmallInteger('sequence');
            $table->date('billing_date');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('status', 20)->default('draft'); // draft, approved
            $money($table, 'cumulative_value'); // work done to date
            $money($table, 'previous_value');
            $money($table, 'current_value');
            $money($table, 'advance_recovery');
            $money($table, 'retention');
            $money($table, 'taxable_amount');
            $money($table, 'vat_amount');
            $money($table, 'net_payable');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'sequence']);
        });

        Schema::create('progress_billing_items', function (Blueprint $table) use ($money, $qty) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('progress_billing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('project_boq_items');
            $qty($table, 'previous_quantity');
            $qty($table, 'current_quantity');
            $table->decimal('unit_price', 18, 4)->default(0);
            $money($table, 'amount');
            $table->timestamps();
        });

        Schema::create('subcontracts', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained();
            $table->string('number', 30);
            $table->string('scope', 1000);
            $money($table, 'contract_value');
            $table->decimal('retention_percent', 5, 2)->default(0);
            $money($table, 'advance_paid');
            $money($table, 'advance_recovered');
            $money($table, 'certified_to_date');
            $money($table, 'retention_held');
            $table->string('status', 20)->default('active'); // active, completed, terminated
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('subcontract_certificates', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subcontract_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->date('date');
            $money($table, 'cumulative_value');
            $money($table, 'current_value');
            $money($table, 'advance_recovery');
            $money($table, 'retention');
            $table->decimal('vat_rate', 5, 2)->default(15);
            $money($table, 'vat_amount');
            $money($table, 'net_payable');
            $table->string('supplier_invoice_number', 50)->nullable();
            $table->string('status', 20)->default('approved');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subcontract_id', 'number']);
        });

        Schema::create('project_costs', function (Blueprint $table) use ($money, $qty) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('project_boq_items')->nullOnDelete();
            $table->string('category', 20); // material, labour, equipment, subcontract, other
            $table->string('source', 20)->default('direct'); // direct, material_issue, material_return, subcontract
            $table->string('reference', 50)->nullable();
            $table->date('date');
            $table->string('description', 500);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fixed_asset_id')->nullable(); // equipment
            $qty($table, 'quantity');
            $table->decimal('unit_cost', 18, 4)->default(0);
            $money($table, 'amount');
            $table->string('paid_from', 20)->nullable(); // cash, bank_transfer, payables, accrued
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'category', 'date']);
        });

        Schema::create('letters_of_guarantee', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // bid, performance, advance_payment, retention
            $table->string('number', 100);
            $table->string('bank_name');
            $table->string('beneficiary');
            $money($table, 'amount');
            $money($table, 'cash_margin');
            $table->date('issue_date');
            $table->date('expiry_date');
            $table->string('status', 20)->default('active'); // active, released, expired, liquidated
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'expiry_date']);
        });

        Schema::create('project_daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('weather', 50)->nullable();
            $table->unsignedSmallInteger('manpower')->default(0);
            $table->string('equipment', 1000)->nullable();
            $table->text('work_done');
            $table->text('issues')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['project_daily_reports', 'letters_of_guarantee', 'project_costs', 'subcontract_certificates', 'subcontracts',
            'progress_billing_items', 'progress_billings', 'project_variations', 'project_boq_items', 'projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

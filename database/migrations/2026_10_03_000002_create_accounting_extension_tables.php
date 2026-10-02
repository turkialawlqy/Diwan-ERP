<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed assets, cheques, bank reconciliation, budgets and recurring journals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->unsignedSmallInteger('useful_life_months')->default(60);
            $table->string('depreciation_method', 20)->default('straight_line'); // straight_line, declining_balance
            $table->decimal('declining_rate', 6, 2)->nullable(); // % per year for declining balance
            $table->foreignId('asset_account_id')->constrained('accounts');
            $table->foreignId('accumulated_account_id')->constrained('accounts');
            $table->foreignId('expense_account_id')->constrained('accounts');
            $table->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_category_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('location')->nullable();
            $table->date('acquisition_date');
            $table->date('depreciation_start_date');
            $table->decimal('cost', 18, 2);
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('depreciation_method', 20)->default('straight_line');
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->date('last_depreciation_date')->nullable();
            $table->string('status', 20)->default('active'); // active, fully_depreciated, disposed
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_amount', 18, 2)->nullable();
            $table->foreignId('acquisition_entry_id')->nullable();
            $table->foreignId('disposal_entry_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('period'); // YYYYMM
            $table->decimal('amount', 18, 2);
            $table->foreignId('journal_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period']);
        });

        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10); // incoming, outgoing
            $table->string('cheque_number', 50);
            $table->string('bank_name')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete(); // our bank account
            $table->string('party_type', 20)->nullable(); // customer, supplier
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('party_name')->nullable();
            $table->decimal('amount', 18, 2);
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status', 20)->default('pending'); // pending (in hand / issued), deposited, cleared, bounced, cancelled
            $table->text('notes')->nullable();
            $table->json('history')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'direction', 'status', 'due_date']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 18, 2);
            $table->decimal('book_balance', 18, 2)->default(0);
            $table->decimal('reconciled_total', 18, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft, completed
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('draft'); // draft, approved
            $table->timestamps();
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('period'); // YYYYMM
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['budget_id', 'account_id']);
        });

        Schema::create('recurring_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->json('lines');
            $table->string('frequency', 20)->default('monthly'); // monthly, quarterly, yearly
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'next_run_date']);
        });
    }

    public function down(): void
    {
        foreach (['recurring_journals', 'budget_lines', 'budgets', 'bank_reconciliations', 'cheques', 'asset_depreciations', 'fixed_assets', 'asset_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

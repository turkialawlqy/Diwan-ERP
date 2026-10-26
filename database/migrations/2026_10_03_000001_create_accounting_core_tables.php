<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrated accounting core: fiscal years / periods, cost centers, journal
 * source links + sub-ledger (party) columns, and pre-aggregated monthly
 * account balances so trial balance / statements stay fast at any volume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open'); // open, closed
            $table->foreignId('closing_entry_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'start_date']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open'); // open, closed (no posting), locked (no posting, cannot reopen)
            $table->timestamps();

            $table->index(['company_id', 'start_date', 'end_date']);
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');
            $table->string('cash_flow_category', 20)->nullable()->after('is_system'); // operating, investing, financing
            $table->boolean('requires_cost_center')->default(false)->after('cash_flow_category');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('status', 20)->default('posted')->change(); // draft, posted, reversed
            $table->string('entry_type', 20)->default('manual')->after('status'); // manual, system, opening, closing, adjustment, recurring
            $table->string('source_type', 100)->nullable()->after('entry_type');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->foreignId('fiscal_period_id')->nullable()->after('source_id');
            $table->foreignId('reversal_of_id')->nullable()->after('fiscal_period_id');
            $table->foreignId('reversed_by_id')->nullable()->after('reversal_of_id');
            $table->timestamp('posted_at')->nullable()->after('reversed_by_id');
            $table->foreignId('posted_by')->nullable()->after('posted_at');

            $table->index(['company_id', 'source_type', 'source_id'], 'je_source_idx');
            $table->index(['company_id', 'status', 'date'], 'je_status_date_idx');
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('account_id');
            $table->string('party_type', 20)->nullable()->after('cost_center_id'); // customer, supplier, employee
            $table->unsignedBigInteger('party_id')->nullable()->after('party_type');
            $table->date('date')->nullable()->after('party_id'); // denormalised entry date for fast ledgers
            $table->foreignId('reconciliation_id')->nullable()->after('date');
            $table->timestamp('reconciled_at')->nullable()->after('reconciliation_id');

            $table->index(['company_id', 'account_id', 'date'], 'jel_account_date_idx');
            $table->index(['company_id', 'party_type', 'party_id', 'date'], 'jel_party_idx');
            $table->index(['company_id', 'cost_center_id', 'date'], 'jel_cost_center_idx');
        });

        Schema::create('account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('period'); // YYYYMM
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'period']);
            $table->index(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balances');

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('jel_account_date_idx');
            $table->dropIndex('jel_party_idx');
            $table->dropIndex('jel_cost_center_idx');
            $table->dropColumn(['cost_center_id', 'party_type', 'party_id', 'date', 'reconciliation_id', 'reconciled_at']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('je_source_idx');
            $table->dropIndex('je_status_date_idx');
            $table->dropColumn(['entry_type', 'source_type', 'source_id', 'fiscal_period_id', 'reversal_of_id', 'reversed_by_id', 'posted_at', 'posted_by']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'cash_flow_category', 'requires_cost_center']);
        });

        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
    }
};

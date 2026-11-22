<?php

use App\Services\Accounting\CashFlowBalances;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly totals per branch and shape of entry, for the cash flow statement: it needs each
 * entry's lines together and the cash accounts are only known when it runs, so the totals are
 * kept per combination of accounts and the cash accounts applied when they are read (see
 * CashFlowBalances). Entries without a branch are kept under branch 0, as in branch_account_balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_flow_balances')) {
            Schema::create('cash_flow_balances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->default(0); // 0 = entries without a branch, so no foreign key
                $table->unsignedInteger('period'); // YYYYMM
                $table->boolean('is_opening')->default(false);
                $table->string('shape', 190);
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->decimal('debit', 20, 2)->default(0);
                $table->decimal('credit', 20, 2)->default(0);
                $table->timestamps();

                $table->unique(['company_id', 'branch_id', 'period', 'is_opening', 'shape', 'account_id'], 'cash_flow_balances_unique');
                $table->index(['company_id', 'period'], 'cash_flow_balances_period_index');
            });
        }

        // The ledger so far, company by company (safe to run again: each rebuild starts empty).
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            app(CashFlowBalances::class)->rebuild((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flow_balances');
    }
};

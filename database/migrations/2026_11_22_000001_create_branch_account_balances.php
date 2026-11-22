<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly totals per account and branch: a report narrowed to a branch reads whole months
 * from here, as a whole-company report does from account_balances, instead of every line.
 *
 * Its own table rather than a branch_id on account_balances: the company totals and the
 * budget screens keep one row per account and month, and the company reports stay on the
 * smaller table. Entries without a branch are kept under branch 0 (a NULL would escape the
 * unique key), so the branches add up to account_balances; no branch report reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_account_balances')) {
            Schema::create('branch_account_balances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->default(0); // 0 = entries without a branch, so no foreign key
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('period'); // YYYYMM
                $table->decimal('debit', 20, 2)->default(0);
                $table->decimal('credit', 20, 2)->default(0);
                $table->timestamps();

                $table->unique(['company_id', 'branch_id', 'account_id', 'period'], 'branch_balances_unique');
                $table->index(['company_id', 'branch_id', 'period'], 'branch_balances_period_index');
            });
        }

        // Every posted line so far, in one statement however long the ledger is (safe to run again).
        DB::table('branch_account_balances')->delete();

        $period = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y%m', l.date) AS INTEGER)"
            : "CAST(DATE_FORMAT(l.date, '%Y%m') AS UNSIGNED)";

        DB::table('branch_account_balances')->insertUsing(
            ['company_id', 'branch_id', 'account_id', 'period', 'debit', 'credit', 'created_at', 'updated_at'],
            DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->whereIn('e.status', ['posted', 'reversed'])
                ->whereNotNull('l.date')
                ->groupBy('l.company_id', DB::raw('COALESCE(e.branch_id, 0)'), 'l.account_id', DB::raw($period))
                ->selectRaw("l.company_id, COALESCE(e.branch_id, 0) as branch_id, l.account_id, {$period} as period, ROUND(SUM(l.debit), 2) as debit, ROUND(SUM(l.credit), 2) as credit, ? as created_at, ? as updated_at", [now(), now()]),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_account_balances');
    }
};

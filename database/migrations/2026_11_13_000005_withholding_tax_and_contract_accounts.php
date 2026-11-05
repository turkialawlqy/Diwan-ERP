<?php

use App\Models\Company;
use App\Services\Accounting\AccountingSetupService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.7.2: withholding tax on payments to non-resident suppliers (supplier flags, the amount kept on the payment),
 * and the accounts for withholding payable and for contract assets / liabilities (revenue by progress).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_non_resident')->default(false);
            $table->string('wht_category', 30)->nullable();
            $table->string('country', 60)->nullable();
        });
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->decimal('wht_rate', 6, 2)->default(0);
            $table->decimal('wht_amount', 15, 2)->default(0);
            $table->string('wht_category', 30)->nullable();
        });

        foreach (Company::cursor() as $company) {
            app(AccountingSetupService::class)->provision($company);
        }
    }

    public function down(): void
    {
        Schema::table('supplier_payments', fn (Blueprint $t) => $t->dropColumn(['wht_rate', 'wht_amount', 'wht_category']));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn(['is_non_resident', 'wht_category', 'country']));
    }
};

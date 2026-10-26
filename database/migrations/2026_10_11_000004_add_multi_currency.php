<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Documents whose amounts may be in a foreign currency. */
    protected array $documents = ['invoices', 'credit_notes', 'debit_notes', 'customer_payments', 'purchase_invoices', 'supplier_payments'];

    public function up(): void
    {
        // Amounts on these documents are in currency_code (null = company base
        // currency); the ledger always posts amount x exchange_rate.
        foreach ($this->documents as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('currency_code', 3)->nullable();
                $t->decimal('exchange_rate', 18, 8)->default(1);
            });
        }

        // Dated rates: a document takes the latest rate on or before its date.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('currency_code', 3);
            $table->date('date');
            $table->decimal('rate', 18, 8);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'currency_code', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        foreach ($this->documents as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['currency_code', 'exchange_rate']));
        }
    }
};

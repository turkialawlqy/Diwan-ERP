<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invoice_number');
            $table->string('reference_number')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->date('supply_date')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->enum('discount_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(15.00);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2)->default(0);
            $table->enum('payment_status', ['paid', 'partially_paid', 'unpaid', 'overdue'])->default('unpaid');
            $table->string('payment_method')->default('cash'); // cash, card, bank_transfer, credit
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            // ZATCA Fatoora (Saudi E-Invoicing) Fields
            $table->uuid('zatca_uuid')->nullable();
            $table->string('zatca_hash')->nullable();
            $table->text('zatca_qr')->nullable();
            $table->enum('zatca_status', ['not_submitted', 'submitted', 'reported', 'accepted', 'rejected'])->default('not_submitted');

            $table->timestamps();

            $table->unique(['company_id', 'invoice_number']);
            $table->index(['company_id', 'payment_status']);
            $table->index(['company_id', 'invoice_date']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A request to pay money out: approved first, then carried out as an expense voucher or a supplier payment. */
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_number', 50);
            $table->date('request_date');
            $table->date('due_date')->nullable();
            $table->string('kind', 20);                          // expense, supplier_payment
            $table->string('payee_type', 20)->default('other');  // supplier, employee, other
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee_name')->nullable();
            $table->string('purpose', 500);
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();           // expense account
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();  // invoice a supplier payment settles
            $table->decimal('amount', 18, 2);
            $table->string('payment_method', 20)->default('bank_transfer');
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft');      // draft, pending_approval, approved, rejected, paid, cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_type', 100)->nullable();        // morph class of the voucher that carried it out
            $table->unsignedBigInteger('paid_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'request_number']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};

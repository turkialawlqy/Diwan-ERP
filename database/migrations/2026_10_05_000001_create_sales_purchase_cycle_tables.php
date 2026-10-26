<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full sales and purchase cycles: sales orders, delivery notes, item-level
 * returns, debit notes, receipt allocation, price lists, purchase requests,
 * goods receipts (GRN), purchase returns, landed costs and approvals.
 */
return new class extends Migration
{
    public function up(): void
    {
        $money = fn (Blueprint $t, string $name) => $t->decimal($name, 18, 2)->default(0);
        $qty = fn (Blueprint $t, string $name) => $t->decimal($name, 18, 4)->default(0);

        // ----- Sales -----
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_quantity', 18, 4)->default(1);
            $table->decimal('price', 18, 4);
            $table->timestamps();

            $table->index(['price_list_id', 'product_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable(); // % of net sales
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable();
            $table->foreignId('delivery_note_id')->nullable();
            $table->string('invoice_type', 20)->default('standard'); // standard (B2B tax invoice), simplified (B2C)
        });

        Schema::create('sales_orders', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('quotation_id')->nullable();
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number', 50);
            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->string('status', 30)->default('draft'); // draft, pending_approval, confirmed, partially_delivered, delivered, invoiced, cancelled
            $money($table, 'subtotal');
            $money($table, 'discount_amount');
            $money($table, 'tax_amount');
            $money($table, 'total');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'order_number']);
            $table->index(['company_id', 'customer_id', 'order_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('sales_order_items', function (Blueprint $table) use ($money, $qty) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable();
            $table->string('item_name');
            $table->text('description')->nullable();
            $qty($table, 'quantity');
            $qty($table, 'delivered_quantity');
            $qty($table, 'invoiced_quantity');
            $table->decimal('unit_price', 18, 4)->default(0);
            $money($table, 'discount');
            $table->decimal('tax_rate', 5, 2)->default(15);
            $money($table, 'tax_amount');
            $money($table, 'total');
            $table->timestamps();
        });

        Schema::create('delivery_notes', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable();
            $table->string('delivery_number', 50);
            $table->date('delivery_date');
            $table->string('status', 20)->default('delivered'); // delivered, invoiced, cancelled
            $money($table, 'total_cost');
            $table->string('receiver_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'delivery_number']);
            $table->index(['company_id', 'customer_id', 'status']);
        });

        Schema::create('delivery_note_items', function (Blueprint $table) use ($qty) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->nullable();
            $table->foreignId('product_id')->constrained();
            $table->string('item_name');
            $qty($table, 'quantity');
            $qty($table, 'invoiced_quantity');
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(15);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->json('serials')->nullable();
            $table->timestamps();
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('amount'); // amount (discount/adjustment), return (goods returned)
            $table->uuid('zatca_uuid')->nullable();
        });

        Schema::create('credit_note_items', function (Blueprint $table) use ($money, $qty) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $qty($table, 'quantity');
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(15);
            $money($table, 'tax_amount');
            $money($table, 'total');
            $table->boolean('return_to_stock')->default(true);
            $table->timestamps();
        });

        Schema::create('debit_notes', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('debit_note_number', 50);
            $table->date('debit_note_date');
            $table->string('reason', 500);
            $money($table, 'subtotal');
            $table->decimal('tax_rate', 5, 2)->default(15);
            $money($table, 'tax_amount');
            $money($table, 'total');
            $table->string('status', 20)->default('approved');
            $table->uuid('zatca_uuid')->nullable();
            $table->text('zatca_qr')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'debit_note_number']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $money($table, 'amount');
            $table->timestamps();

            $table->index(['company_id', 'invoice_id']);
        });

        // ----- Purchases -----
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_number', 50);
            $table->date('request_date');
            $table->date('required_date')->nullable();
            $table->string('status', 30)->default('draft'); // draft, pending_approval, approved, rejected, ordered, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'request_number']);
        });

        Schema::create('purchase_request_items', function (Blueprint $table) use ($qty) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $qty($table, 'quantity');
            $table->decimal('estimated_price', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_request_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('terms')->nullable();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) use ($qty) {
            $qty($table, 'received_quantity');
            $qty($table, 'invoiced_quantity');
        });

        Schema::create('goods_receipts', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable();
            $table->string('receipt_number', 50);
            $table->date('receipt_date');
            $table->string('supplier_delivery_number')->nullable();
            $table->string('status', 20)->default('received'); // received, invoiced, cancelled
            $money($table, 'total_value');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'receipt_number']);
            $table->index(['company_id', 'supplier_id', 'status']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) use ($qty) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable();
            $table->foreignId('product_id')->constrained();
            $table->string('item_name');
            $qty($table, 'quantity');
            $qty($table, 'invoiced_quantity');
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->json('serials')->nullable();
            $table->timestamps();
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('source', 20)->default('direct'); // direct (receives stock), grn (stock already received)
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->foreignId('goods_receipt_item_id')->nullable();
            $table->foreignId('purchase_order_item_id')->nullable();
            $table->decimal('returned_quantity', 18, 4)->default(0);
        });

        Schema::create('purchase_returns', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('return_number', 50);
            $table->date('return_date');
            $table->string('reason', 500)->nullable();
            $table->string('settlement', 20)->default('credit'); // credit (reduce payable), cash, bank_transfer
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $money($table, 'subtotal');
            $money($table, 'tax_amount');
            $money($table, 'total');
            $money($table, 'cost_value');
            $table->string('status', 20)->default('approved');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'return_number']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) use ($money, $qty) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_invoice_item_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $qty($table, 'quantity');
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(15);
            $money($table, 'tax_amount');
            $money($table, 'total');
            $table->json('serials')->nullable();
            $table->timestamps();
        });

        Schema::create('landed_costs', function (Blueprint $table) use ($money) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('landed_number', 50);
            $table->date('date');
            $table->string('description');
            $money($table, 'amount');
            $money($table, 'tax_amount');
            $table->string('paid_from', 20)->default('payables'); // payables, cash, bank_transfer
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->json('allocation')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'landed_number']);
        });

        // ----- Approvals -----
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 50); // purchase_order, purchase_request, sales_order, expense
            $table->decimal('min_amount', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'document_type']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('approvable_type', 100);
            $table->unsignedBigInteger('approvable_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20); // submitted, approved, rejected
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id']);
        });
    }

    public function down(): void
    {
        foreach (['approvals', 'approval_rules', 'landed_costs', 'purchase_return_items', 'purchase_returns', 'goods_receipt_items', 'goods_receipts',
            'purchase_request_items', 'purchase_requests', 'payment_allocations', 'debit_notes', 'credit_note_items', 'delivery_note_items',
            'delivery_notes', 'sales_order_items', 'sales_orders', 'price_list_items'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('purchase_invoice_items', fn (Blueprint $t) => $t->dropColumn(['goods_receipt_item_id', 'purchase_order_item_id', 'returned_quantity']));
        Schema::table('purchase_invoices', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('purchase_order_items', fn (Blueprint $t) => $t->dropColumn(['received_quantity', 'invoiced_quantity']));
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('branch_id');
            $t->dropConstrainedForeignId('created_by');
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['purchase_request_id', 'approved_at', 'terms']);
        });
        Schema::table('credit_notes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('warehouse_id');
            $t->dropColumn(['kind', 'zatca_uuid']);
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('salesperson_id');
            $t->dropColumn(['sales_order_id', 'delivery_note_id', 'invoice_type']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('commission_rate'));
        Schema::table('customers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('price_list_id');
            $t->dropConstrainedForeignId('salesperson_id');
            $t->dropColumn('payment_terms_days');
        });
        Schema::dropIfExists('price_lists');
    }
};

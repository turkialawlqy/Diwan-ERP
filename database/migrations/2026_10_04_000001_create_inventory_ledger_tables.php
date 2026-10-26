<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory ledger: every quantity change is a stock movement (with cost),
 * FIFO cost layers, units of measure, batches / expiry, serial numbers,
 * two-step transfers, count documents and assembly (BOM) orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tracking', 10)->default('none')->after('track_inventory'); // none, batch, serial
            $table->decimal('reorder_level', 18, 4)->nullable()->after('min_stock_alert');
            $table->decimal('reorder_quantity', 18, 4)->nullable()->after('reorder_level');
            $table->boolean('is_kit')->default(false)->after('tracking');
        });

        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->string('bin_location', 50)->nullable()->after('quantity');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('factor', 18, 6); // how many base units in one of this unit
            $table->string('barcode', 100)->nullable();
            $table->decimal('selling_price', 18, 4)->nullable();
            $table->decimal('purchase_price', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
            $table->index(['company_id', 'barcode']);
        });

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 100);
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id', 'batch_number']);
            $table->index(['company_id', 'expiry_date']);
        });

        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number', 150);
            $table->string('status', 20)->default('in_stock'); // in_stock, sold, returned, scrapped
            $table->string('last_source_type', 100)->nullable();
            $table->unsignedBigInteger('last_source_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'serial_number']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->string('type', 30); // opening, purchase, purchase_return, sale, sale_return, transfer_out, transfer_in, adjustment, count, damage, assembly_in, assembly_out, project_issue
            $table->date('date');
            $table->decimal('quantity', 18, 4); // signed: + in / - out (base unit)
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0); // signed like quantity
            $table->decimal('balance_quantity', 18, 4)->default(0); // warehouse balance after the movement
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'warehouse_id', 'date'], 'sm_product_wh_date_idx');
            $table->index(['company_id', 'date'], 'sm_company_date_idx');
            $table->index(['company_id', 'source_type', 'source_id'], 'sm_source_idx');
            $table->index(['company_id', 'type', 'date'], 'sm_type_date_idx');
        });

        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->date('date');
            $table->decimal('quantity', 18, 4);
            $table->decimal('remaining', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'remaining', 'date'], 'layers_fifo_idx');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('transfer_number', 50);
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');
            $table->date('transfer_date');
            $table->string('status', 20)->default('draft'); // draft, in_transit, received, cancelled
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'transfer_number']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('received_quantity', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('count_number', 50);
            $table->foreignId('warehouse_id')->constrained();
            $table->date('count_date');
            $table->string('count_type', 20)->default('full'); // full, partial (spot)
            $table->string('status', 20)->default('draft'); // draft, approved, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'count_number']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('system_quantity', 18, 4)->default(0);
            $table->decimal('counted_quantity', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
        });

        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete(); // finished good
            $table->string('name');
            $table->decimal('output_quantity', 18, 4)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('products');
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
        });

        Schema::create('assembly_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('order_number', 50);
            $table->foreignId('bom_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity', 18, 4); // finished units produced
            $table->date('date');
            $table->string('status', 20)->default('draft'); // draft, completed
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'order_number']);
        });

        // Kits: products sold as a bundle of components.
        Schema::create('product_kit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kit_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('products');
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
        });

        // Opening movements for stock that existed before the ledger.
        $now = now();
        DB::table('inventory_stocks')->where('quantity', '!=', 0)->orderBy('id')->chunkById(500, function ($stocks) use ($now) {
            $rows = [];
            foreach ($stocks as $stock) {
                $cost = (float) (DB::table('products')->where('id', $stock->product_id)->value('average_cost') ?? 0);
                $rows[] = [
                    'company_id' => $stock->company_id,
                    'product_id' => $stock->product_id,
                    'warehouse_id' => $stock->warehouse_id,
                    'type' => 'opening',
                    'date' => $now->toDateString(),
                    'quantity' => $stock->quantity,
                    'unit_cost' => $cost,
                    'total_cost' => round($cost * (float) $stock->quantity, 2),
                    'balance_quantity' => $stock->quantity,
                    'reference' => 'رصيد مرحّل',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('stock_movements')->insert($rows);
        });

        DB::table('stock_movements')->where('type', 'opening')->where('quantity', '>', 0)->orderBy('id')->chunkById(500, function ($movements) use ($now) {
            DB::table('stock_layers')->insert($movements->map(fn ($m) => [
                'company_id' => $m->company_id,
                'product_id' => $m->product_id,
                'warehouse_id' => $m->warehouse_id,
                'movement_id' => $m->id,
                'date' => $m->date,
                'quantity' => $m->quantity,
                'remaining' => $m->quantity,
                'unit_cost' => $m->unit_cost,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        foreach (['product_kit_items', 'assembly_orders', 'bom_items', 'boms', 'stock_count_items', 'stock_counts', 'stock_transfer_items', 'stock_transfers', 'stock_layers', 'stock_movements', 'product_serials', 'product_batches', 'product_units'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('warehouse_id'));
        Schema::table('inventory_stocks', fn (Blueprint $t) => $t->dropColumn('bin_location'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['tracking', 'reorder_level', 'reorder_quantity', 'is_kit']));
    }
};

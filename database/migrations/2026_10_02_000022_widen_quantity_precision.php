<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantities were stored with 2 decimals, which truncates weights, lengths and
 * unit conversions (e.g. 0.125 kg). Store them with 4 decimals.
 */
return new class extends Migration
{
    protected array $columns = [
        'invoice_items' => ['quantity' => 1],
        'quotation_items' => ['quantity' => 1],
        'pos_order_items' => ['quantity' => 1],
        'purchase_invoice_items' => ['quantity' => 1],
        'purchase_order_items' => ['quantity' => 1],
        'inventory_stocks' => ['quantity' => 0],
        'products' => ['min_stock_alert' => 5],
    ];

    public function up(): void
    {
        $this->apply(18, 4);
    }

    public function down(): void
    {
        $this->apply(15, 2);
    }

    protected function apply(int $precision, int $scale): void
    {
        foreach ($this->columns as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns, $precision, $scale) {
                foreach ($columns as $column => $default) {
                    $t->decimal($column, $precision, $scale)->default($default)->change();
                }
            });
        }
    }
};

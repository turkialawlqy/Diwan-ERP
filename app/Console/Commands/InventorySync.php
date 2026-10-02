<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the movements ledger in line with warehouse balances for stock that
 * was entered outside StockService (imports, legacy data, demo seeders): the
 * difference becomes an "opening" movement with a matching FIFO layer.
 */
class InventorySync extends Command
{
    protected $signature = 'erp:inventory-sync {--company= : Only this company id}';

    protected $description = 'Create opening stock movements for balances not explained by the movements ledger';

    public function handle(): int
    {
        Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->each(function (Company $company) {
            $created = 0;
            $now = now();

            DB::table('inventory_stocks')->where('company_id', $company->id)->orderBy('id')->chunkById(500, function ($stocks) use ($company, $now, &$created) {
                foreach ($stocks as $stock) {
                    $ledger = (float) DB::table('stock_movements')
                        ->where('product_id', $stock->product_id)
                        ->where('warehouse_id', $stock->warehouse_id)
                        ->sum('quantity');
                    $difference = round((float) $stock->quantity - $ledger, 4);

                    if ($difference == 0.0) {
                        continue;
                    }

                    $cost = (float) (DB::table('products')->where('id', $stock->product_id)->value('average_cost') ?: DB::table('products')->where('id', $stock->product_id)->value('purchase_price'));

                    $movementId = DB::table('stock_movements')->insertGetId([
                        'company_id' => $company->id,
                        'product_id' => $stock->product_id,
                        'warehouse_id' => $stock->warehouse_id,
                        'type' => 'opening',
                        'date' => $now->toDateString(),
                        'quantity' => $difference,
                        'unit_cost' => $cost,
                        'total_cost' => round($difference * $cost, 2),
                        'balance_quantity' => $stock->quantity,
                        'reference' => 'مزامنة رصيد',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if ($difference > 0) {
                        DB::table('stock_layers')->insert([
                            'company_id' => $company->id, 'product_id' => $stock->product_id, 'warehouse_id' => $stock->warehouse_id,
                            'movement_id' => $movementId, 'date' => $now->toDateString(), 'quantity' => $difference, 'remaining' => $difference,
                            'unit_cost' => $cost, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }

                    $created++;
                }
            });

            $this->line("{$company->name}: {$created} opening movements");
        });

        return self::SUCCESS;
    }
}

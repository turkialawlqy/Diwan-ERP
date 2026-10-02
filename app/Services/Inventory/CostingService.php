<?php

namespace App\Services\Inventory;

use App\Models\Product;

/**
 * Unit cost used for cost of goods sold and stock valuation.
 * Uses the product's moving average cost when available, otherwise its
 * purchase price.
 */
class CostingService
{
    public function unitCost(?int $productId, ?int $warehouseId = null): float
    {
        if (! $productId) {
            return 0.0;
        }

        $product = Product::withoutGlobalScopes()->find($productId, ['id', 'purchase_price', 'average_cost', 'track_inventory']);

        if (! $product || ! $product->track_inventory) {
            return 0.0;
        }

        $average = (float) ($product->average_cost ?? 0);

        return round($average > 0 ? $average : (float) $product->purchase_price, 4);
    }
}

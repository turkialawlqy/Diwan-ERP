<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;

/**
 * Selling price for a customer: the customer's price list (or the default
 * list), the best quantity break that applies, else the product price.
 */
class PriceResolver
{
    public function price(Product $product, ?Customer $customer = null, float $quantity = 1, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $listId = $customer?->price_list_id ?? PriceList::where('is_default', true)->where('is_active', true)->value('id');

        if ($listId) {
            $list = PriceList::whereKey($listId)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
                ->first();

            $item = $list ? PriceListItem::where('price_list_id', $list->id)->where('product_id', $product->id)
                ->where('min_quantity', '<=', max($quantity, 0))
                ->orderByDesc('min_quantity')->first() : null;

            if ($item) {
                return ['price' => (float) $item->price, 'source' => $list->name];
            }
        }

        return ['price' => (float) $product->selling_price, 'source' => null];
    }
}

<?php

namespace App\Services\WorkOrders;

use App\Models\Product;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Inventory\DocumentStock;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parts leave stock when they are consumed (at their cost) and are billed later at their price.
 * No journal is booked here: the invoice books the cost of goods once, from the cost it is given.
 */
class PartsService
{
    public function __construct(protected StockService $stock, protected DocumentStock $documentStock, protected WorkOrderSettings $settings) {}

    public function consume(WorkOrder $order, int $productId, float $quantity, ?float $price = null, ?int $warehouseId = null): WorkOrderPart
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['status' => 'أمر العمل مغلق.']);
        }
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'الكمية يجب أن تكون أكبر من صفر.']);
        }
        $product = Product::findOrFail($productId);

        return DB::transaction(function () use ($order, $product, $quantity, $price, $warehouseId) {
            $warehouse = $this->documentStock->warehouse($warehouseId ?? $order->warehouse_id ?? $this->settings->all()['default_warehouse']);
            $part = WorkOrderPart::create([
                'work_order_id' => $order->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse, 'quantity' => $quantity,
                'unit_price' => $price ?? (float) $product->selling_price, 'issued_by' => auth()->id(),
            ]);

            if ($product->isStockItem()) {
                $value = $this->stock->issue(['product_id' => $product->id, 'warehouse_id' => $warehouse, 'quantity' => $quantity, 'type' => 'work_order_issue',
                    'date' => now()->toDateString(), 'source' => $part, 'reference' => $order->number]);
                $part->update(['unit_cost' => round($value / $quantity, 4)]);
            }

            return $part;
        });
    }

    /** Give a part back to the warehouse it came from (at the cost it left with). */
    public function giveBack(WorkOrderPart $part, bool $force = false): WorkOrderPart
    {
        $order = $part->workOrder ?? WorkOrder::findOrFail($part->work_order_id);
        if (! $force && ! $order->isOpen()) {
            throw ValidationException::withMessages(['status' => 'أمر العمل مغلق.']);
        }
        if ($part->returned) {
            throw ValidationException::withMessages(['part' => 'أُرجعت هذه القطعة من قبل.']);
        }

        return DB::transaction(function () use ($part, $order) {
            if (Product::find($part->product_id)?->isStockItem()) {
                $this->stock->receive(['product_id' => $part->product_id, 'warehouse_id' => $part->warehouse_id, 'quantity' => (float) $part->quantity, 'unit_cost' => (float) $part->unit_cost,
                    'keep_average' => true, 'type' => 'work_order_return', 'date' => now()->toDateString(), 'source' => $part, 'reference' => $order->number]);
            }
            $part->update(['returned' => true]);

            return $part;
        });
    }

    /** Cost of the parts still on the order. */
    public function cost(WorkOrder $order): float
    {
        return round((float) $order->parts()->where('returned', false)->get()->sum(fn ($p) => (float) $p->quantity * (float) $p->unit_cost), 2);
    }

    public function amount(WorkOrder $order): float
    {
        return round((float) $order->parts()->where('returned', false)->get()->sum(fn ($p) => (float) $p->quantity * (float) $p->unit_price), 2);
    }
}

<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Applies the lines of a document (invoice, bill, return, delivery...) to
 * stock through StockService, converting sale/purchase units to the base unit.
 *
 * Each line: product_id, quantity, unit_id? (alternative unit), unit_cost?,
 * batch_number? / batch_id?, expiry_date?, serials?[]
 */
class DocumentStock
{
    public function __construct(protected StockService $stock) {}

    /**
     * Issue lines out of a warehouse. Returns the total cost issued.
     */
    public function issue(iterable $lines, ?int $warehouseId, string $type, Model $source, $date, ?string $reference = null): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $line = (array) $line;
            if (empty($line['product_id'])) {
                continue;
            }

            $warehouseId = $this->warehouse($warehouseId);
            $total += $this->stock->issue([
                'product_id' => (int) $line['product_id'],
                'warehouse_id' => $warehouseId,
                'quantity' => $this->baseQuantity($line),
                'type' => $type,
                'date' => $date,
                'source' => $source,
                'reference' => $reference,
                'batch_id' => $line['batch_id'] ?? null,
                'serials' => $line['serials'] ?? [],
            ]);
        }

        return round($total, 2);
    }

    /**
     * Receive lines into a warehouse. Returns the total value received.
     */
    public function receive(iterable $lines, ?int $warehouseId, string $type, Model $source, $date, ?string $reference = null, bool $keepAverage = false): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $line = (array) $line;
            if (empty($line['product_id'])) {
                continue;
            }

            $product = Product::find($line['product_id']);
            if (! $product || ! $product->isStockItem()) {
                continue;
            }

            $quantity = $this->baseQuantity($line);
            $unitCost = isset($line['unit_cost'])
                ? (float) $line['unit_cost'] / $this->factor($line)
                : (float) $product->average_cost;

            $this->stock->receive([
                'product_id' => $product->id,
                'warehouse_id' => $this->warehouse($warehouseId),
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'type' => $type,
                'date' => $date,
                'source' => $source,
                'reference' => $reference,
                'batch_number' => $line['batch_number'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'serials' => $line['serials'] ?? [],
                'keep_average' => $keepAverage,
            ]);

            $total += $quantity * $unitCost;
        }

        return round($total, 2);
    }

    public function baseQuantity(array $line): float
    {
        return round((float) $line['quantity'] * $this->factor($line), 4);
    }

    protected function factor(array $line): float
    {
        if (empty($line['unit_id'])) {
            return 1.0;
        }

        $unit = ProductUnit::where('product_id', $line['product_id'])->where('unit_id', $line['unit_id'])->first();

        return $unit ? (float) $unit->factor : 1.0;
    }

    /**
     * The document's warehouse, or the company's main warehouse.
     */
    public function warehouse(?int $warehouseId): int
    {
        $warehouseId ??= Warehouse::where('is_main', true)->value('id') ?? Warehouse::orderBy('id')->value('id');

        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => 'يجب إنشاء مستودع واحد على الأقل قبل تسجيل حركات المخزون.']);
        }

        return (int) $warehouseId;
    }
}

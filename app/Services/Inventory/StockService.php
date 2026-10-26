<?php

namespace App\Services\Inventory;

use App\Models\Company;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\StockLayer;
use App\Models\StockMovement;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way stock quantities change.
 *
 * Every change writes a stock movement (quantity + cost) and updates the
 * warehouse balance under row locks. Costing is the company's choice:
 * moving weighted average (default) or FIFO cost layers. Batch (with expiry,
 * issued FEFO) and serial tracking are enforced per product; kits issue their
 * components.
 */
class StockService
{
    public function __construct(protected TenantManager $tenantManager) {}

    /**
     * Stock in. Returns the movement.
     *
     * $data: product_id, warehouse_id, quantity (> 0, base unit), unit_cost,
     *        type, date?, source? (Model), reference?, notes?,
     *        batch_number?, expiry_date?, manufacture_date?, serials? [],
     *        keep_average? (transfers / returns at current cost)
     */
    public function receive(array $data): StockMovement
    {
        $quantity = round((float) $data['quantity'], 4);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'الكمية يجب أن تكون أكبر من صفر.']);
        }

        return DB::transaction(function () use ($data, $quantity) {
            $product = $this->lockProduct($data['product_id']);
            $unitCost = round((float) ($data['unit_cost'] ?? $product->average_cost ?? 0), 4);
            $stock = $this->lockStock($product->id, $data['warehouse_id']);

            // Moving average across all warehouses of the company.
            if (empty($data['keep_average'])) {
                $onHand = (float) InventoryStock::where('product_id', $product->id)->sum('quantity');
                $average = $onHand > 0
                    ? (($onHand * (float) $product->average_cost) + ($quantity * $unitCost)) / ($onHand + $quantity)
                    : $unitCost;
                $product->forceFill(['average_cost' => round($average, 4)])->saveQuietly();
            }

            $batchId = $this->receiveBatch($product, $data, $quantity);
            $this->receiveSerials($product, $data, $quantity);

            // Stock below zero was already issued (costed at the average):
            // the receipt first covers that deficit, so its FIFO layer only
            // holds what is physically left and nothing is costed twice.
            $deficit = max(0, -(float) $stock->quantity);
            $stock->quantity = (float) $stock->quantity + $quantity;
            $stock->save();

            $movement = $this->movement($product, $data, $quantity, $unitCost, (float) $stock->quantity, $batchId);

            StockLayer::create([
                'product_id' => $product->id,
                'warehouse_id' => $data['warehouse_id'],
                'movement_id' => $movement->id,
                'date' => $movement->date,
                'quantity' => $quantity,
                'remaining' => max(0, round($quantity - $deficit, 4)),
                'unit_cost' => $unitCost,
            ]);

            return $movement;
        });
    }

    /**
     * Stock out. Returns the total cost of the quantity issued (for COGS).
     *
     * $data: product_id, warehouse_id, quantity (> 0, base unit), type,
     *        date?, source?, reference?, notes?, batch_id?, serials? []
     */
    public function issue(array $data): float
    {
        $quantity = round((float) $data['quantity'], 4);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'الكمية يجب أن تكون أكبر من صفر.']);
        }

        return DB::transaction(function () use ($data, $quantity) {
            $product = $this->lockProduct($data['product_id']);

            // Kits are not stocked: their components are.
            if ($product->is_kit) {
                $total = 0.0;
                foreach ($product->kitItems()->get() as $component) {
                    $total += $this->issue(array_merge($data, [
                        'product_id' => $component->component_id,
                        'quantity' => $quantity * (float) $component->quantity,
                        'batch_id' => null,
                        'serials' => [],
                    ]));
                }

                return round($total, 2);
            }

            if (! $product->isStockItem()) {
                return 0.0;
            }

            $stock = $this->lockStock($product->id, $data['warehouse_id']);
            $available = (float) $stock->quantity;

            if ($available + 0.00005 < $quantity && ! $this->allowsNegativeStock()) {
                $name = $product->name_ar;
                throw ValidationException::withMessages([
                    'items' => "الكمية المتوفرة من «{$name}» في المستودع غير كافية (المتوفر: ".round($available, 4).'، المطلوب: '.$quantity.').',
                ]);
            }

            $this->issueSerials($product, $data, $quantity);

            // Batch tracked items are allocated FEFO (earliest expiry first),
            // possibly across several batches.
            $allocations = $product->tracking === 'batch'
                ? $this->allocateBatches($product, $data, $quantity)
                : [[null, $quantity]];

            $totalCost = 0.0;
            foreach ($allocations as [$batchId, $qty]) {
                $cost = $this->consumeCost($product, (int) $data['warehouse_id'], $qty);
                $totalCost += $cost;

                $stock->quantity = (float) $stock->quantity - $qty;
                $this->movement($product, $data, -$qty, $qty > 0 ? $cost / $qty : 0, (float) $stock->quantity, $batchId, -$cost);
            }

            $stock->save();

            return round($totalCost, 2);
        });
    }

    /**
     * Move stock between warehouses at cost (average unchanged).
     */
    public function transfer(int $productId, int $fromWarehouseId, int $toWarehouseId, float $quantity, array $context = []): float
    {
        return DB::transaction(function () use ($productId, $fromWarehouseId, $toWarehouseId, $quantity, $context) {
            $batch = ! empty($context['batch_id']) ? ProductBatch::find($context['batch_id']) : null;

            $cost = $this->issue(array_merge($context, [
                'product_id' => $productId,
                'warehouse_id' => $fromWarehouseId,
                'quantity' => $quantity,
                'type' => 'transfer_out',
            ]));

            $this->receive(array_merge($context, [
                'product_id' => $productId,
                'warehouse_id' => $toWarehouseId,
                'quantity' => $quantity,
                'unit_cost' => $quantity > 0 ? $cost / $quantity : 0,
                'type' => 'transfer_in',
                'keep_average' => true,
                'batch_number' => $batch?->batch_number,
                'expiry_date' => $batch?->expiry_date?->toDateString(),
            ]));

            return $cost;
        });
    }

    /**
     * Set the warehouse quantity to a counted figure. Returns the signed value
     * of the difference (positive = surplus).
     */
    public function adjustTo(int $productId, int $warehouseId, float $countedQuantity, array $context = []): float
    {
        return DB::transaction(function () use ($productId, $warehouseId, $countedQuantity, $context) {
            $current = (float) $this->lockStock($productId, $warehouseId)->quantity;
            $difference = round($countedQuantity - $current, 4);

            if ($difference > 0) {
                $product = Product::findOrFail($productId);
                $this->receive(array_merge($context, [
                    'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $difference,
                    'unit_cost' => (float) $product->average_cost, 'keep_average' => true,
                ]));

                return round($difference * (float) $product->average_cost, 2);
            }

            if ($difference < 0) {
                return -$this->issue(array_merge($context, [
                    'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => -$difference,
                ]));
            }

            return 0.0;
        });
    }

    /**
     * Quantity on hand of a product in a warehouse (or all warehouses).
     */
    public function onHand(int $productId, ?int $warehouseId = null): float
    {
        return (float) InventoryStock::where('product_id', $productId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('quantity');
    }

    /**
     * Quantity of a product in a warehouse as of a date, from the movements ledger.
     */
    public function quantityAsOf(int $productId, int $warehouseId, Carbon $date): float
    {
        return (float) StockMovement::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('date', '<=', $date->toDateString().' 23:59:59')
            ->sum('quantity');
    }

    protected function consumeCost(Product $product, int $warehouseId, float $quantity): float
    {
        $remaining = $quantity;
        $fifoCost = 0.0;

        // Layers are always consumed so FIFO stays consistent if the method changes.
        $layers = StockLayer::where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->where('remaining', '>', 0)
            ->orderBy('date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $layer->remaining);
            $layer->remaining = round((float) $layer->remaining - $take, 4);
            $layer->save();
            $fifoCost += $take * (float) $layer->unit_cost;
            $remaining -= $take;
        }

        // Quantity without layers (negative stock) is costed at the average,
        // or the purchase price when nothing was received yet (never at zero).
        $fallback = (float) $product->average_cost > 0 ? (float) $product->average_cost : (float) $product->purchase_price;
        $fifoCost += max(0, $remaining) * $fallback;

        return round($this->costingMethod() === 'fifo' ? $fifoCost : $quantity * $fallback, 2);
    }

    protected function receiveBatch(Product $product, array $data, float $quantity): ?int
    {
        if ($product->tracking !== 'batch') {
            return null;
        }

        $number = trim((string) ($data['batch_number'] ?? ''));
        if ($number === '') {
            throw ValidationException::withMessages(['items' => "الصنف «{$product->name_ar}» يتطلب رقم تشغيلة (Batch)."]);
        }

        $batch = ProductBatch::firstOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $data['warehouse_id'], 'batch_number' => $number],
            ['expiry_date' => $data['expiry_date'] ?? null, 'manufacture_date' => $data['manufacture_date'] ?? null, 'quantity' => 0],
        );
        $batch = ProductBatch::whereKey($batch->id)->lockForUpdate()->first();
        $batch->quantity = (float) $batch->quantity + $quantity;
        if (! empty($data['expiry_date']) && ! $batch->expiry_date) {
            $batch->expiry_date = $data['expiry_date'];
        }
        $batch->save();

        return $batch->id;
    }

    protected function allocateBatches(Product $product, array $data, float $quantity): array
    {
        $query = ProductBatch::where('product_id', $product->id)
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('quantity', '>', 0)
            ->lockForUpdate();

        $batches = ! empty($data['batch_id'])
            ? $query->whereKey($data['batch_id'])->get()
            : $query->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->orderBy('id')->get();

        $allocations = [];
        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $batch->quantity);
            $batch->quantity = round((float) $batch->quantity - $take, 4);
            $batch->save();
            $allocations[] = [$batch->id, $take];
            $remaining = round($remaining - $take, 4);
        }

        if ($remaining > 0) {
            if (! $this->allowsNegativeStock()) {
                throw ValidationException::withMessages(['items' => "كميات التشغيلات المتاحة من «{$product->name_ar}» غير كافية."]);
            }
            $allocations[] = [null, $remaining];
        }

        return $allocations;
    }

    protected function receiveSerials(Product $product, array $data, float $quantity): void
    {
        if ($product->tracking !== 'serial') {
            return;
        }

        $serials = array_values(array_unique(array_filter(array_map('trim', $data['serials'] ?? []))));
        if (count($serials) !== (int) round($quantity)) {
            throw ValidationException::withMessages(['items' => "الصنف «{$product->name_ar}» يتطلب إدخال {$quantity} رقم تسلسلي."]);
        }

        foreach ($serials as $serial) {
            $existing = ProductSerial::where('product_id', $product->id)->where('serial_number', $serial)->first();
            if ($existing && $existing->status === 'in_stock') {
                throw ValidationException::withMessages(['items' => "الرقم التسلسلي {$serial} موجود بالمخزون مسبقاً."]);
            }

            ProductSerial::updateOrCreate(
                ['product_id' => $product->id, 'serial_number' => $serial],
                ['warehouse_id' => $data['warehouse_id'], 'status' => 'in_stock', 'last_source_type' => $this->sourceType($data), 'last_source_id' => $this->sourceId($data)],
            );
        }
    }

    protected function issueSerials(Product $product, array $data, float $quantity): void
    {
        if ($product->tracking !== 'serial') {
            return;
        }

        $serials = array_values(array_unique(array_filter(array_map('trim', $data['serials'] ?? []))));
        if (count($serials) !== (int) round($quantity)) {
            throw ValidationException::withMessages(['items' => "يجب تحديد {$quantity} رقم تسلسلي للصنف «{$product->name_ar}»."]);
        }

        $found = ProductSerial::where('product_id', $product->id)
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('status', 'in_stock')
            ->whereIn('serial_number', $serials)
            ->lockForUpdate()
            ->get();

        if ($found->count() !== count($serials)) {
            throw ValidationException::withMessages(['items' => "بعض الأرقام التسلسلية للصنف «{$product->name_ar}» غير متوفرة في المستودع."]);
        }

        $status = in_array($data['type'] ?? '', ['transfer_out'], true) ? 'in_transit' : (($data['type'] ?? '') === 'damage' ? 'scrapped' : 'sold');

        ProductSerial::whereIn('id', $found->pluck('id'))->update([
            'status' => $status,
            'warehouse_id' => $status === 'sold' || $status === 'scrapped' ? null : $data['warehouse_id'],
            'last_source_type' => $this->sourceType($data),
            'last_source_id' => $this->sourceId($data),
        ]);
    }

    /**
     * Value-only movement (quantity 0): landed costs and price variances
     * capitalised on stock, so the valuation report matches the ledger.
     */
    public function revalue(int $productId, int $warehouseId, float $value, array $context = []): ?StockMovement
    {
        if (abs($value) < 0.005) {
            return null;
        }
        $product = Product::findOrFail($productId);
        $balance = (float) InventoryStock::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity');

        return $this->movement($product, $context + ['warehouse_id' => $warehouseId, 'type' => 'revaluation'], 0, 0, $balance, null, $value);
    }

    protected function movement(Product $product, array $data, float $quantity, float $unitCost, float $balance, ?int $batchId, ?float $totalCost = null): StockMovement
    {
        return StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $data['warehouse_id'],
            'batch_id' => $batchId,
            'type' => $data['type'] ?? ($quantity > 0 ? 'adjustment' : 'adjustment'),
            'date' => Carbon::parse($data['date'] ?? now())->toDateString(),
            'quantity' => $quantity,
            'unit_cost' => round($unitCost, 4),
            'total_cost' => round($totalCost ?? $quantity * $unitCost, 2),
            'balance_quantity' => round($balance, 4),
            'source_type' => $this->sourceType($data),
            'source_id' => $this->sourceId($data),
            'reference' => $data['reference'] ?? null,
            'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 250) : null,
            'created_by' => auth()->id(),
        ]);
    }

    protected function lockProduct(int $productId): Product
    {
        return Product::whereKey($productId)->lockForUpdate()->firstOrFail();
    }

    protected function lockStock(int $productId, int $warehouseId): InventoryStock
    {
        $stock = InventoryStock::firstOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            ['quantity' => 0],
        );

        return InventoryStock::whereKey($stock->id)->lockForUpdate()->first();
    }

    protected function sourceType(array $data): ?string
    {
        return isset($data['source']) && $data['source'] instanceof Model ? $data['source']->getMorphClass() : ($data['source_type'] ?? null);
    }

    protected function sourceId(array $data): ?int
    {
        return isset($data['source']) && $data['source'] instanceof Model ? $data['source']->getKey() : ($data['source_id'] ?? null);
    }

    protected function company(): ?Company
    {
        return $this->tenantManager->getCompany();
    }

    public function costingMethod(): string
    {
        return $this->company()?->setting('costing_method', 'average') === 'fifo' ? 'fifo' : 'average';
    }

    protected function allowsNegativeStock(): bool
    {
        return (bool) $this->company()?->setting('allow_negative_stock', false);
    }
}

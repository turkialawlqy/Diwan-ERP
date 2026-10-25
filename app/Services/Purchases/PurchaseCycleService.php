<?php

namespace App\Services\Purchases;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryStock;
use App\Models\LandedCost;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\StockLayer;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\ExchangeRates;
use App\Services\Documents\DocumentNumberService;
use App\Services\Inventory\DocumentStock;
use App\Services\Inventory\StockService;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Services\Workflow\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Procure-to-pay: purchase request → purchase order → goods receipt (GRN)
 * → supplier invoice (three-way match) → purchase return, plus landed costs.
 */
class PurchaseCycleService
{
    public const QTY_TOLERANCE = 0.00005;

    public function __construct(
        protected DocumentNumberService $numbers,
        protected DocumentStock $documentStock,
        protected StockService $stock,
        protected DocumentPoster $poster,
        protected ApprovalService $approvals,
    ) {}

    // ----- Purchase requests -----

    public function createRequest(array $data, array $lines): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $lines) {
            $request = PurchaseRequest::create([
                'branch_id' => $data['branch_id'] ?? auth()->user()?->defaultBranchId(),
                'request_number' => $this->numbers->next('purchase_request', Carbon::parse($data['request_date'])),
                'request_date' => $data['request_date'],
                'required_date' => $data['required_date'] ?? null,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'requested_by' => auth()->id(),
            ]);

            $estimate = 0.0;
            foreach ($lines as $line) {
                $request->items()->create([
                    'product_id' => $line['product_id'] ?? null,
                    'item_name' => $line['item_name'],
                    'quantity' => (float) $line['quantity'],
                    'estimated_price' => (float) ($line['estimated_price'] ?? 0),
                ]);
                $estimate += (float) $line['quantity'] * (float) ($line['estimated_price'] ?? 0);
            }

            $this->approvals->submit($request, 'purchase_request', $estimate, 'approved');

            return $request;
        });
    }

    // ----- Purchase orders -----

    /**
     * @param  array  $lines  [product_id?, item_name, quantity, unit_price, discount?, tax_rate?]
     */
    public function createOrder(array $data, array $lines, ?PurchaseOrder $order = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $lines, $order) {
            if ($order && ! in_array($order->status, ['draft', 'rejected'], true)) {
                throw ValidationException::withMessages(['order' => 'لا يمكن تعديل أمر شراء بعد إرساله للاعتماد.']);
            }

            $branchId = $data['branch_id'] ?? auth()->user()?->defaultBranchId();
            [$items, $subtotal, $tax, $discount] = $this->computeLines($lines, TaxProfile::current($branchId ? (int) $branchId : null));

            $attributes = [
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'branch_id' => $branchId,
                'purchase_request_id' => $data['purchase_request_id'] ?? $order?->purchase_request_id,
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'subtotal' => $subtotal,
                'tax_rate' => TaxProfile::rate(),
                'tax_amount' => $tax,
                'discount' => $discount,
                'total' => round($subtotal + $tax, 2),
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ];

            if ($order) {
                $order->update($attributes + ['status' => 'draft']);
                $order->items()->delete();
            } else {
                $order = PurchaseOrder::create($attributes + [
                    'order_number' => $this->numbers->next('purchase_order', Carbon::parse($data['order_date'])),
                    'status' => 'draft',
                    'created_by' => auth()->id(),
                ]);
            }

            foreach ($items as $item) {
                $order->items()->create($item);
            }

            if ($order->purchase_request_id) {
                PurchaseRequest::whereKey($order->purchase_request_id)->where('status', 'approved')->update(['status' => 'ordered']);
            }

            if (($data['submit'] ?? false) === true) {
                $this->approvals->submit($order, 'purchase_order', (float) $order->total, 'approved');
            }

            return $order->fresh('items');
        });
    }

    public function submitOrder(PurchaseOrder $order): string
    {
        if (! in_array($order->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['order' => 'أمر الشراء ليس مسودة.']);
        }

        return $this->approvals->submit($order, 'purchase_order', (float) $order->total, 'approved');
    }

    public function cancelOrder(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (GoodsReceipt::where('purchase_order_id', $order->id)->where('status', '!=', 'cancelled')->exists()
                || in_array($order->status, ['converted_to_invoice', 'received'], true)) {
                throw ValidationException::withMessages(['order' => 'لا يمكن إلغاء أمر شراء تم الاستلام عليه.']);
            }
            $order->update(['status' => 'cancelled']);
        });
    }

    // ----- Goods receipts -----

    /**
     * Receive goods (against a PO or directly). Stock enters at the order's
     * net unit price; inventory is debited against "goods received not invoiced".
     *
     * @param  array  $lines  [purchase_order_item_id | product_id+item_name+unit_cost, quantity, unit_id?, batch_number?, expiry_date?, serials?]
     */
    public function receive(?PurchaseOrder $order, array $data, array $lines): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $data, $lines) {
            if ($order) {
                $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (! in_array($order->status, ['approved', 'pending', 'partially_received'], true)) {
                    throw ValidationException::withMessages(['order' => 'أمر الشراء غير معتمد أو مستلم بالكامل.']);
                }
            }

            $receipt = GoodsReceipt::create([
                'branch_id' => $order?->branch_id ?? auth()->user()?->defaultBranchId(),
                'warehouse_id' => $this->documentStock->warehouse($data['warehouse_id'] ?? $order?->warehouse_id),
                'supplier_id' => $order?->supplier_id ?? $data['supplier_id'],
                'purchase_order_id' => $order?->id,
                'receipt_number' => $this->numbers->next('goods_receipt', Carbon::parse($data['receipt_date'])),
                'receipt_date' => $data['receipt_date'],
                'supplier_delivery_number' => $data['supplier_delivery_number'] ?? null,
                'status' => 'received',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $total = 0.0;
            foreach ($lines as $line) {
                $quantity = (float) $line['quantity'];
                if ($quantity <= 0) {
                    continue;
                }

                $orderItem = null;
                if ($order) {
                    $orderItem = PurchaseOrderItem::where('purchase_order_id', $order->id)->whereKey($line['purchase_order_item_id'] ?? 0)->lockForUpdate()->firstOrFail();
                    $open = (float) $orderItem->quantity - (float) $orderItem->received_quantity;
                    $tolerance = (float) (app(TenantManager::class)->getCompany()?->setting('over_receipt_percent', 0) ?? 0);
                    if ($quantity > $open + ((float) $orderItem->quantity * $tolerance / 100) + self::QTY_TOLERANCE) {
                        throw ValidationException::withMessages(['lines' => "الكمية المستلمة من «{$orderItem->item_name}» أكبر من المتبقي على أمر الشراء ({$open})."]);
                    }
                    $orderItem->increment('received_quantity', $quantity);
                }

                $productId = $orderItem?->product_id ?? ($line['product_id'] ?? null);
                if (! $productId) {
                    continue; // services are not received into stock
                }

                $unitCost = $orderItem
                    ? ((float) $orderItem->quantity > 0 ? ((float) $orderItem->quantity * (float) $orderItem->unit_price - (float) $orderItem->discount) / (float) $orderItem->quantity : 0)
                    : (float) ($line['unit_cost'] ?? 0);

                $value = $this->documentStock->receive([[
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_id' => $line['unit_id'] ?? null,
                    'unit_cost' => $unitCost,
                    'batch_number' => $line['batch_number'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'serials' => $line['serials'] ?? [],
                ]], $receipt->warehouse_id, 'purchase', $receipt, $receipt->receipt_date, $receipt->receipt_number);

                $receipt->items()->create([
                    'purchase_order_item_id' => $orderItem?->id,
                    'product_id' => $productId,
                    'item_name' => $orderItem?->item_name ?? $line['item_name'],
                    'quantity' => $quantity,
                    'unit_cost' => round($unitCost, 4),
                    'batch_number' => $line['batch_number'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'serials' => $line['serials'] ?? null,
                ]);
                $total += $value;
            }

            if (! $receipt->items()->exists()) {
                throw ValidationException::withMessages(['lines' => 'حدد كمية مستلمة واحدة على الأقل.']);
            }

            $receipt->update(['total_value' => round($total, 2)]);
            $this->poster->goodsReceipt($receipt);

            if ($order) {
                $this->refreshOrderStatus($order);
            }

            return $receipt;
        });
    }

    public function refreshOrderStatus(PurchaseOrder $order): void
    {
        $order->load('items');
        $ordered = $order->items->sum(fn ($i) => (float) $i->quantity);
        $received = $order->items->sum(fn ($i) => (float) $i->received_quantity);
        $invoiced = $order->items->sum(fn ($i) => (float) $i->invoiced_quantity);

        $status = match (true) {
            $invoiced >= $ordered - self::QTY_TOLERANCE => 'converted_to_invoice',
            $received >= $ordered - self::QTY_TOLERANCE => 'received',
            $received > 0 => 'partially_received',
            default => $order->status,
        };

        $order->update(['status' => $status]);
    }

    // ----- Supplier invoice from receipts (three-way match) -----

    /**
     * @param  array  $lines  [goods_receipt_item_id, quantity, unit_price, discount?, tax_rate?]
     */
    public function invoiceReceipts(array $data, array $lines): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $lines) {
            $this->assertUniqueSupplierInvoice((int) $data['supplier_id'], $data['supplier_invoice_number'] ?? null);

            $tolerance = (float) (app(TenantManager::class)->getCompany()?->setting('price_variance_percent', 0) ?? 0);
            $rows = [];
            $receivedValue = 0.0;
            $receiptIds = [];

            foreach ($lines as $line) {
                $quantity = (float) $line['quantity'];
                if ($quantity <= 0) {
                    continue;
                }

                $grnItem = GoodsReceiptItem::with('receipt')->whereKey($line['goods_receipt_item_id'])->lockForUpdate()->firstOrFail();
                if ((int) $grnItem->receipt->supplier_id !== (int) $data['supplier_id'] || $grnItem->receipt->status === 'cancelled') {
                    throw ValidationException::withMessages(['lines' => 'أحد الاستلامات لا يخص المورد المحدد.']);
                }

                $open = (float) $grnItem->quantity - (float) $grnItem->invoiced_quantity;
                if ($quantity > $open + self::QTY_TOLERANCE) {
                    throw ValidationException::withMessages(['lines' => "الكمية المفوترة من «{$grnItem->item_name}» أكبر من المستلم غير المفوتر ({$open})."]);
                }

                // Price check against the purchase order (three-way match).
                $unitPrice = (float) $line['unit_price'];
                if ($grnItem->purchase_order_item_id && $tolerance >= 0) {
                    $poItem = PurchaseOrderItem::find($grnItem->purchase_order_item_id);
                    $poPrice = (float) ($poItem?->unit_price ?? 0);
                    if ($poItem && $poPrice > 0 && $unitPrice > $poPrice * (1 + $tolerance / 100) + 0.0001 && ! ($data['accept_price_variance'] ?? false)) {
                        throw ValidationException::withMessages(['lines' => "سعر «{$grnItem->item_name}» في الفاتورة ({$unitPrice}) أعلى من سعر أمر الشراء ({$poPrice})."]);
                    }
                }

                $grnItem->increment('invoiced_quantity', $quantity);
                // Only stock lines went through GRNI on receipt (services carry no value there).
                $isStock = $grnItem->product_id && Product::find($grnItem->product_id)?->isStockItem();
                if ($isStock) {
                    $receivedValue += round((float) $grnItem->unit_cost * $quantity, 2);
                }
                $receiptIds[$grnItem->goods_receipt_id] = true;

                $rows[] = [
                    'line' => [
                        'product_id' => $grnItem->product_id,
                        'item_name' => $grnItem->item_name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'discount' => (float) ($line['discount'] ?? 0),
                        'tax_rate' => (float) ($line['tax_rate'] ?? TaxProfile::rate()),
                    ],
                    'grn_item' => $grnItem,
                    'stock' => $isStock,
                ];
            }

            if (! $rows) {
                throw ValidationException::withMessages(['lines' => 'حدد صنفاً واحداً على الأقل للفوترة.']);
            }

            [$items, $subtotal, $tax, $discount] = $this->computeLines(array_column($rows, 'line'));
            $total = round($subtotal + $tax, 2);
            [$paid, $remaining, $status] = $this->payment($data, $total);

            $invoice = PurchaseInvoice::create([
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? GoodsReceipt::whereIn('id', array_keys($receiptIds))->value('purchase_order_id'),
                'warehouse_id' => GoodsReceipt::whereIn('id', array_keys($receiptIds))->value('warehouse_id'),
                'branch_id' => $data['branch_id'] ?? auth()->user()?->defaultBranchId(),
                'invoice_number' => $this->numbers->next('purchase_invoice', Carbon::parse($data['invoice_date'])),
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'supply_date' => $data['supply_date'] ?? null,
                'payment_method' => $data['payment_method'] ?? 'credit',
                'payment_status' => $status,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax_rate' => TaxProfile::rate(),
                'tax_amount' => $tax,
                'total' => $total,
                'paid_amount' => $paid,
                'remaining_amount' => $remaining,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source' => 'grn',
            ]);

            foreach ($items as $i => $item) {
                $grnItem = $rows[$i]['grn_item'];
                $invoice->items()->create($item + [
                    'goods_receipt_item_id' => $grnItem->id,
                    'purchase_order_item_id' => $grnItem->purchase_order_item_id,
                ]);
                if ($grnItem->purchase_order_item_id) {
                    PurchaseOrderItem::whereKey($grnItem->purchase_order_item_id)->increment('invoiced_quantity', $item['quantity']);
                }
            }

            foreach (array_keys($receiptIds) as $receiptId) {
                $receipt = GoodsReceipt::with('items')->find($receiptId);
                $fully = $receipt->items->every(fn ($i) => (float) $i->invoiced_quantity >= (float) $i->quantity - self::QTY_TOLERANCE);
                if ($fully) {
                    $receipt->update(['status' => 'invoiced', 'purchase_invoice_id' => $invoice->id]);
                }
                if ($receipt->purchase_order_id) {
                    $this->refreshOrderStatus(PurchaseOrder::find($receipt->purchase_order_id));
                }
            }

            if ($remaining > 0) {
                Supplier::whereKey($invoice->supplier_id)->increment('current_balance', $remaining);
            }

            // Invoice price above/below the receipt cost: the part for goods
            // still in stock goes to inventory (and the average cost), only the
            // part for goods already sold is an expense.
            $invoice->load('items');
            $toInventory = 0.0;
            foreach ($items as $i => $item) {
                if (! $rows[$i]['stock']) {
                    continue;
                }
                $grnItem = $rows[$i]['grn_item'];
                $variance = round((float) $item['quantity'] * (float) $item['unit_price'] - (float) ($item['discount'] ?? 0) - (float) $grnItem->unit_cost * (float) $item['quantity'], 2);
                if ($variance != 0.0) {
                    $toInventory += $this->capitalise((int) $grnItem->product_id, $invoice, $variance, (float) $item['quantity'], ['type' => 'revaluation', 'source' => $invoice,
                        'date' => $invoice->invoice_date, 'reference' => $invoice->invoice_number, 'notes' => 'فرق سعر الفاتورة عن الاستلام']);
                }
            }

            $this->poster->purchaseInvoiceFromReceipts($invoice, $receivedValue, round($toInventory, 2));

            return $invoice;
        });
    }

    public function assertUniqueSupplierInvoice(int $supplierId, ?string $supplierInvoiceNumber, ?int $ignoreId = null): void
    {
        if (! $supplierInvoiceNumber) {
            return;
        }

        $exists = PurchaseInvoice::where('supplier_id', $supplierId)
            ->where('supplier_invoice_number', $supplierInvoiceNumber)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['supplier_invoice_number' => 'فاتورة المورد بهذا الرقم مسجلة مسبقاً.']);
        }
    }

    // ----- Purchase returns -----

    /**
     * @param  array  $lines  [purchase_invoice_item_id, quantity, serials?, batch_id?]
     */
    public function returnInvoice(PurchaseInvoice $invoice, array $data, array $lines): PurchaseReturn
    {
        return DB::transaction(function () use ($invoice, $data, $lines) {
            $invoice = PurchaseInvoice::with('items')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $warehouseId = $this->documentStock->warehouse($data['warehouse_id'] ?? $invoice->warehouse_id);
            $settlement = $data['settlement'] ?? 'credit';

            $return = PurchaseReturn::create([
                'supplier_id' => $invoice->supplier_id,
                'purchase_invoice_id' => $invoice->id,
                'warehouse_id' => $warehouseId,
                'return_number' => $this->numbers->next('purchase_return', Carbon::parse($data['return_date'])),
                'return_date' => $data['return_date'],
                'reason' => $data['reason'] ?? null,
                'settlement' => $settlement,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'status' => 'approved',
                'created_by' => auth()->id(),
            ]);

            $subtotal = 0.0;
            $tax = 0.0;
            $cost = 0.0;
            foreach ($lines as $line) {
                $quantity = (float) $line['quantity'];
                if ($quantity <= 0) {
                    continue;
                }

                $item = $invoice->items->firstWhere('id', (int) $line['purchase_invoice_item_id']);
                if (! $item) {
                    throw ValidationException::withMessages(['lines' => 'أحد الأصناف لا يخص الفاتورة.']);
                }
                $open = (float) $item->quantity - (float) $item->returned_quantity;
                if ($quantity > $open + self::QTY_TOLERANCE) {
                    throw ValidationException::withMessages(['lines' => "كمية المرتجع من «{$item->item_name}» أكبر من المتبقي ({$open})."]);
                }

                $unitNet = (float) $item->quantity > 0 ? ((float) $item->quantity * (float) $item->unit_price - (float) $item->discount) / (float) $item->quantity : 0;
                $lineNet = round($unitNet * $quantity, 2);
                $lineTax = round($lineNet * (float) $item->tax_rate / 100, 2);

                if ($item->product_id && Product::find($item->product_id)?->isStockItem()) {
                    $cost += $this->stock->issue([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $quantity,
                        'type' => 'purchase_return',
                        'date' => $return->return_date,
                        'source' => $return,
                        'reference' => $return->return_number,
                        'serials' => $line['serials'] ?? [],
                        'batch_id' => $line['batch_id'] ?? null,
                    ]);
                }

                $item->increment('returned_quantity', $quantity);
                $return->items()->create([
                    'purchase_invoice_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'item_name' => $item->item_name,
                    'quantity' => $quantity,
                    'unit_price' => $unitNet,
                    'tax_rate' => (float) $item->tax_rate,
                    'tax_amount' => $lineTax,
                    'total' => round($lineNet + $lineTax, 2),
                    'serials' => $line['serials'] ?? null,
                ]);

                $subtotal += $lineNet;
                $tax += $lineTax;
            }

            if ($subtotal <= 0) {
                throw ValidationException::withMessages(['lines' => 'حدد كمية مرتجعة واحدة على الأقل.']);
            }

            $total = round($subtotal + $tax, 2);
            $return->update([
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($tax, 2),
                'total' => $total,
                'cost_value' => round($cost, 2),
            ]);

            if ($settlement === 'credit') {
                $applied = min($total, (float) $invoice->remaining_amount);
                if ($applied > 0) {
                    $remaining = round((float) $invoice->remaining_amount - $applied, 2);
                    $invoice->update(['remaining_amount' => $remaining, 'payment_status' => $remaining <= 0 ? 'paid' : 'partially_paid']);
                }
                Supplier::whereKey($invoice->supplier_id)->decrement('current_balance', ExchangeRates::toBase($total, $invoice->exchange_rate));
            }

            $this->poster->purchaseReturn($return->fresh());

            return $return;
        });
    }

    // ----- Landed costs -----

    /**
     * Spread freight / customs / clearing over the invoice's stock lines by
     * value. The share of goods still on hand raises their average cost; the
     * share of goods already sold goes straight to cost of sales.
     */
    public function landedCost(PurchaseInvoice $invoice, array $data): LandedCost
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice->load('items');
            $stockItems = $invoice->items->filter(fn ($i) => $i->product_id && Product::find($i->product_id)?->isStockItem());
            $base = $stockItems->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_price - (float) $i->discount);
            if ($base <= 0) {
                throw ValidationException::withMessages(['purchase_invoice_id' => 'الفاتورة لا تحتوي أصناف مخزنية لتوزيع التكاليف عليها.']);
            }

            $amount = round((float) $data['amount'], 2);
            $landed = LandedCost::create([
                'purchase_invoice_id' => $invoice->id,
                'landed_number' => $this->numbers->next('landed_cost', Carbon::parse($data['date'])),
                'date' => $data['date'],
                'description' => $data['description'],
                'amount' => $amount,
                'tax_amount' => round((float) ($data['tax_amount'] ?? 0), 2),
                'paid_from' => $data['paid_from'] ?? 'payables',
                'supplier_id' => $data['supplier_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $toInventory = 0.0;
            $toCogs = 0.0;
            $allocation = [];
            foreach ($stockItems->groupBy('product_id') as $productId => $items) {
                $lineValue = $items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_price - (float) $i->discount);
                $quantity = $items->sum(fn ($i) => (float) $i->quantity - (float) $i->returned_quantity);
                $share = round($amount * $lineValue / $base, 2);
                if ($share <= 0 || $quantity <= 0) {
                    continue;
                }

                $inventoryShare = $this->capitalise((int) $productId, $invoice, $share, $quantity, ['type' => 'revaluation', 'source' => $landed,
                    'date' => $landed->date, 'reference' => $landed->landed_number, 'notes' => 'تكاليف إضافية: '.$landed->description]);

                $toInventory += $inventoryShare;
                $toCogs += $share - $inventoryShare;
                $allocation[] = ['product_id' => (int) $productId, 'share' => $share, 'inventory' => $inventoryShare, 'cogs' => round($share - $inventoryShare, 2)];
            }

            // Rounding residue goes with the largest line.
            $diff = round($amount - $toInventory - $toCogs, 2);
            if ($diff != 0.0) {
                $toInventory > 0 ? $toInventory += $diff : $toCogs += $diff;
            }

            $landed->update(['allocation' => $allocation]);
            if ($landed->paid_from === 'payables' && $landed->supplier_id) {
                Supplier::whereKey($landed->supplier_id)->increment('current_balance', $amount + (float) $landed->tax_amount);
            }

            $this->poster->landedCost($landed, round($toInventory, 2), round($toCogs, 2));

            return $landed;
        });
    }

    /**
     * Puts an extra cost (or a price variance) of $quantity purchased units
     * onto the stock still held: average cost, FIFO layers and a value-only
     * stock movement. Returns the part capitalised; the rest belongs to
     * goods already sold (cost of sales).
     */
    protected function capitalise(int $productId, PurchaseInvoice $invoice, float $amount, float $quantity, array $context): float
    {
        if ($quantity <= 0 || $amount == 0.0) {
            return 0.0;
        }
        $product = Product::whereKey($productId)->lockForUpdate()->first();
        $onHand = (float) InventoryStock::where('product_id', $productId)->sum('quantity');
        $stillHeld = max(0, min($quantity, $onHand));
        $share = round($amount * $stillHeld / $quantity, 2);
        if ($share == 0.0 || $onHand <= 0) {
            return 0.0;
        }

        $product->forceFill(['average_cost' => round(max(0, ((float) $product->average_cost * $onHand + $share) / $onHand), 4)])->saveQuietly();
        $this->raiseLayers($productId, $invoice, $share);
        $warehouseId = $invoice->warehouse_id ?: (int) InventoryStock::where('product_id', $productId)->where('quantity', '>', 0)->value('warehouse_id');
        if ($warehouseId) {
            $this->stock->revalue($productId, $warehouseId, $share, $context);
        }

        return $share;
    }

    /**
     * FIFO companies: add the landed cost to the open layers of this invoice
     * (or its receipts) so later issues carry it.
     */
    protected function raiseLayers(int $productId, PurchaseInvoice $invoice, float $amount): void
    {
        if ($this->stock->costingMethod() !== 'fifo') {
            return;
        }

        $sources = [[$invoice->getMorphClass(), [$invoice->id]]];
        $receiptIds = GoodsReceiptItem::whereIn('id', $invoice->items->pluck('goods_receipt_item_id')->filter())->pluck('goods_receipt_id')->unique()->all();
        if ($receiptIds) {
            $sources[] = [(new GoodsReceipt)->getMorphClass(), $receiptIds];
        }

        $movementIds = StockMovement::where('product_id', $productId)
            ->where(function ($q) use ($sources) {
                foreach ($sources as [$type, $ids]) {
                    $q->orWhere(fn ($q) => $q->where('source_type', $type)->whereIn('source_id', $ids));
                }
            })->pluck('id');

        $layers = StockLayer::whereIn('movement_id', $movementIds)->where('remaining', '>', 0)->lockForUpdate()->get();
        $remaining = $layers->sum(fn ($l) => (float) $l->remaining);
        if ($remaining <= 0) {
            return;
        }

        foreach ($layers as $layer) {
            $layer->update(['unit_cost' => round((float) $layer->unit_cost + $amount / $remaining, 4)]);
        }
    }

    // ----- helpers -----

    public function computeLines(array $lines, ?TaxProfile $profile = null): array
    {
        $items = [];
        $subtotal = 0.0;
        $tax = 0.0;
        $discountTotal = 0.0;

        foreach ($lines as $line) {
            $quantity = (float) $line['quantity'];
            $price = (float) $line['unit_price'];
            $discount = round((float) ($line['discount'] ?? 0), 2);
            $rate = (float) ($line['tax_rate'] ?? TaxProfile::rate());
            // With the document's branch known, a taxed line carries a rate its country allows
            // (zero-rated and exempt lines stay at 0; a country without VAT charges none).
            if ($profile && ($rate > 0 || ! isset($line['tax_rate']) || $line['tax_rate'] === '')) {
                $rate = $profile->chargesTax() ? $profile->rateFor($rate) : 0.0;
            }
            if ($quantity <= 0 || $price < 0 || $discount < 0) {
                throw ValidationException::withMessages(['items' => 'كميات وأسعار الأصناف يجب أن تكون موجبة.']);
            }

            $net = round($quantity * $price - $discount, 2);
            if ($net < 0) {
                throw ValidationException::withMessages(['items' => 'خصم السطر لا يمكن أن يتجاوز قيمته.']);
            }
            $lineTax = round($net * $rate / 100, 2);

            $items[] = [
                'product_id' => $line['product_id'] ?? null,
                'item_name' => $line['item_name'],
                'quantity' => $quantity,
                'unit_price' => $price,
                'discount' => $discount,
                'tax_rate' => $rate,
                'tax_amount' => $lineTax,
                'total' => round($net + $lineTax, 2),
            ];
            $subtotal += $net;
            $tax += $lineTax;
            $discountTotal += $discount;
        }

        if (! $items) {
            throw ValidationException::withMessages(['items' => 'يجب إضافة صنف واحد على الأقل.']);
        }

        return [$items, round($subtotal, 2), round($tax, 2), round($discountTotal, 2)];
    }

    protected function payment(array $data, float $total): array
    {
        $requested = ($data['payment_status'] ?? 'unpaid') === 'paid' ? $total : (float) ($data['paid_amount'] ?? 0);
        $paid = round(min(max($requested, 0), $total), 2);
        $remaining = max(0, round($total - $paid, 2));

        return [$paid, $remaining, $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid')];
    }
}

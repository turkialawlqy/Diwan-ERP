<?php

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DebitNote;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\ExchangeRates;
use App\Services\Documents\DocumentNumberService;
use App\Services\Inventory\DocumentStock;
use App\Services\Inventory\StockService;
use App\Services\Saas\WebhookDispatcher;
use App\Services\Tax\TaxProfile;
use App\Services\Workflow\ApprovalService;
use App\Services\Zatca\EInvoiceManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Order-to-cash: sales orders → delivery notes → invoices, item level
 * returns (credit notes), debit notes and receipt allocation.
 */
class SalesCycleService
{
    public function __construct(
        protected DocumentNumberService $numbers,
        protected InvoiceService $invoices,
        protected DocumentStock $documentStock,
        protected StockService $stock,
        protected DocumentPoster $poster,
        protected ApprovalService $approvals,
    ) {}

    public function createOrder(array $data, array $lines): SalesOrder
    {
        return DB::transaction(function () use ($data, $lines) {
            $branchId = $data['branch_id'] ?? auth()->user()?->defaultBranchId();
            // Lines follow the tax rules of the order's branch (its country).
            [$items, $subtotal, $tax] = $this->invoices->computeLines($lines, TaxProfile::current($branchId ? (int) $branchId : null), $data['order_date'] ?? null);

            $order = SalesOrder::create([
                'branch_id' => $branchId,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'quotation_id' => $data['quotation_id'] ?? null,
                'salesperson_id' => $data['salesperson_id'] ?? auth()->id(),
                'order_number' => $this->numbers->next('sales_order', Carbon::parse($data['order_date'])),
                'order_date' => $data['order_date'],
                'delivery_date' => $data['delivery_date'] ?? null,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'discount_amount' => round(array_sum(array_column($items, 'discount')), 2),
                'tax_amount' => $tax,
                'total' => round($subtotal + $tax, 2),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $i => $item) {
                $order->items()->create(array_merge(collect($item)->except(['subtotal', 'vat_category', 'vat_exemption_code'])->all(), ['unit_id' => $lines[$i]['unit_id'] ?? null]));
            }

            $this->approvals->submit($order, 'sales_order', (float) $order->total, 'confirmed');

            return $order;
        });
    }

    /**
     * Deliver order lines (or a direct delivery without order). Stock leaves
     * the warehouse and cost of sales is recognised now.
     *
     * @param  array  $lines  [sales_order_item_id? | product_id+item_name+unit_price+tax_rate, quantity, serials?, batch_id?]
     */
    public function deliver(?SalesOrder $order, array $data, array $lines): DeliveryNote
    {
        return DB::transaction(function () use ($order, $data, $lines) {
            if ($order) {
                $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (! in_array($order->status, ['confirmed', 'partially_delivered'], true)) {
                    throw ValidationException::withMessages(['order' => 'أمر البيع غير معتمد أو مسلّم بالكامل.']);
                }
            }

            $note = DeliveryNote::create([
                'branch_id' => $order?->branch_id ?? auth()->user()?->defaultBranchId(),
                'warehouse_id' => $this->documentStock->warehouse($data['warehouse_id'] ?? $order?->warehouse_id),
                'customer_id' => $order?->customer_id ?? $data['customer_id'],
                'sales_order_id' => $order?->id,
                'delivery_number' => $this->numbers->next('delivery_note', Carbon::parse($data['delivery_date'])),
                'delivery_date' => $data['delivery_date'],
                'status' => 'delivered',
                'receiver_name' => $data['receiver_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $totalCost = 0.0;
            foreach ($lines as $line) {
                $quantity = (float) $line['quantity'];
                if ($quantity <= 0) {
                    continue;
                }

                $orderItem = null;
                if ($order) {
                    $orderItem = SalesOrderItem::where('sales_order_id', $order->id)->whereKey($line['sales_order_item_id'] ?? 0)->lockForUpdate()->firstOrFail();
                    $open = (float) $orderItem->quantity - (float) $orderItem->delivered_quantity;
                    if ($quantity > $open + 0.00005) {
                        throw ValidationException::withMessages(['lines' => "الكمية المطلوب تسليمها من «{$orderItem->item_name}» أكبر من المتبقي ({$open})."]);
                    }
                    $orderItem->increment('delivered_quantity', $quantity);
                }

                $productId = $orderItem?->product_id ?? $line['product_id'];
                $cost = $productId ? $this->documentStock->issue([[
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_id' => $orderItem?->unit_id ?? ($line['unit_id'] ?? null),
                    'serials' => $line['serials'] ?? [],
                    'batch_id' => $line['batch_id'] ?? null,
                ]], $note->warehouse_id, 'delivery', $note, $note->delivery_date, $note->delivery_number) : 0;

                $note->items()->create([
                    'sales_order_item_id' => $orderItem?->id,
                    'product_id' => $productId,
                    'item_name' => $orderItem?->item_name ?? $line['item_name'],
                    'quantity' => $quantity,
                    'unit_price' => $orderItem?->unit_price ?? ($line['unit_price'] ?? 0),
                    'tax_rate' => $orderItem?->tax_rate ?? ($line['tax_rate'] ?? TaxProfile::rate()),
                    'unit_cost' => $quantity > 0 ? $cost / $quantity : 0,
                    'serials' => $line['serials'] ?? null,
                ]);
                $totalCost += $cost;
            }

            if (! $note->items()->exists()) {
                throw ValidationException::withMessages(['lines' => 'حدد كمية واحدة على الأقل للتسليم.']);
            }

            $note->update(['total_cost' => round($totalCost, 2)]);
            $this->poster->deliveryCost($note, $totalCost);

            if ($order) {
                $this->refreshOrderStatus($order);
            }

            return $note;
        });
    }

    /**
     * Invoice everything not yet invoiced on a delivery note (cost already posted).
     */
    public function invoiceDelivery(DeliveryNote $note, array $data = []): Invoice
    {
        return DB::transaction(function () use ($note, $data) {
            $note = DeliveryNote::with('items')->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($note->status !== 'delivered') {
                throw ValidationException::withMessages(['delivery' => 'إذن التسليم مفوتر أو ملغى.']);
            }

            $lines = $note->items->map(function ($item) {
                $orderItem = $item->sales_order_item_id ? SalesOrderItem::find($item->sales_order_item_id) : null;
                $discountPerUnit = $orderItem && (float) $orderItem->quantity > 0 ? (float) $orderItem->discount / (float) $orderItem->quantity : 0;

                return [
                    'product_id' => $item->product_id,
                    'item_name' => $item->item_name,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => round($discountPerUnit * (float) $item->quantity, 2),
                    'tax_rate' => (float) $item->tax_rate,
                ];
            })->all();

            $invoice = $this->invoices->create(array_merge([
                'customer_id' => $note->customer_id,
                'branch_id' => $note->branch_id,
                'warehouse_id' => $note->warehouse_id,
                'invoice_date' => now()->toDateString(),
                'payment_method' => 'credit',
                'payment_status' => 'unpaid',
                'sales_order_id' => $note->sales_order_id,
                'delivery_note_id' => $note->id,
                'reference_number' => $note->delivery_number,
            ], $data), $lines, ['issue_stock' => false, 'cost' => 0]);

            foreach ($note->items as $item) {
                $item->update(['invoiced_quantity' => $item->quantity]);
                if ($item->sales_order_item_id) {
                    SalesOrderItem::whereKey($item->sales_order_item_id)->increment('invoiced_quantity', (float) $item->quantity);
                }
            }

            $note->update(['status' => 'invoiced', 'invoice_id' => $invoice->id]);

            if ($note->sales_order_id) {
                $this->refreshOrderStatus(SalesOrder::find($note->sales_order_id));
            }

            return $invoice;
        });
    }

    /**
     * Invoice an order directly (services or deliver-and-invoice at once).
     */
    public function invoiceOrder(SalesOrder $order, array $data = []): Invoice
    {
        return DB::transaction(function () use ($order, $data) {
            $order = SalesOrder::with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['confirmed', 'partially_delivered', 'delivered'], true)) {
                throw ValidationException::withMessages(['order' => 'لا يمكن فوترة أمر البيع في حالته الحالية.']);
            }

            // Only lines not yet delivered are invoiced here (delivered ones are
            // invoiced from their delivery notes).
            $lines = [];
            foreach ($order->items as $item) {
                $open = (float) $item->quantity - max((float) $item->delivered_quantity, (float) $item->invoiced_quantity);
                if ($open <= 0) {
                    continue;
                }
                $lines[] = [
                    'product_id' => $item->product_id,
                    'unit_id' => $item->unit_id,
                    'item_name' => $item->item_name,
                    'description' => $item->description,
                    'quantity' => $open,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => (float) $item->quantity > 0 ? round((float) $item->discount * $open / (float) $item->quantity, 2) : 0,
                    'tax_rate' => (float) $item->tax_rate,
                ];
                $item->update(['delivered_quantity' => (float) $item->delivered_quantity + $open, 'invoiced_quantity' => (float) $item->invoiced_quantity + $open]);
            }

            if (! $lines) {
                throw ValidationException::withMessages(['order' => 'لا توجد كميات متبقية للفوترة على هذا الأمر.']);
            }

            $invoice = $this->invoices->create(array_merge([
                'customer_id' => $order->customer_id,
                'branch_id' => $order->branch_id,
                'warehouse_id' => $order->warehouse_id,
                'salesperson_id' => $order->salesperson_id,
                'invoice_date' => now()->toDateString(),
                'payment_method' => 'credit',
                'payment_status' => 'unpaid',
                'sales_order_id' => $order->id,
                'reference_number' => $order->order_number,
            ], $data), $lines);

            $this->refreshOrderStatus($order);

            return $invoice;
        });
    }

    public function refreshOrderStatus(SalesOrder $order): void
    {
        $order->load('items');
        $ordered = $order->items->sum(fn ($i) => (float) $i->quantity);
        $delivered = $order->items->sum(fn ($i) => (float) $i->delivered_quantity);
        $invoiced = $order->items->sum(fn ($i) => (float) $i->invoiced_quantity);

        $status = match (true) {
            $invoiced >= $ordered - 0.00005 => 'invoiced',
            $delivered >= $ordered - 0.00005 => 'delivered',
            $delivered > 0 => 'partially_delivered',
            default => $order->status,
        };

        $order->update(['status' => $status]);
    }

    /**
     * Item level sales return against an invoice (or amount-only credit when
     * no lines are given). Returned goods go back to stock at their original cost.
     *
     * @param  array  $lines  [invoice_item_id, quantity, return_to_stock?, serials?]
     */
    public function returnInvoice(Invoice $invoice, array $data, array $lines): CreditNote
    {
        return DB::transaction(function () use ($invoice, $data, $lines) {
            $invoice = Invoice::with('items')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $warehouseId = $this->documentStock->warehouse($data['warehouse_id'] ?? $invoice->warehouse_id);

            $noteLines = [];
            foreach ($lines as $line) {
                $quantity = (float) $line['quantity'];
                if ($quantity <= 0) {
                    continue;
                }

                $item = $invoice->items->firstWhere('id', (int) $line['invoice_item_id']);
                if (! $item) {
                    throw ValidationException::withMessages(['lines' => 'أحد الأصناف لا يخص الفاتورة.']);
                }

                $returned = (float) CreditNoteItem::where('invoice_item_id', $item->id)
                    ->whereHas('creditNote', fn ($q) => $q->whereIn('status', ['approved', 'refunded']))
                    ->sum('quantity');
                if ($quantity > (float) $item->quantity - $returned + 0.00005) {
                    throw ValidationException::withMessages(['lines' => "كمية المرتجع من «{$item->item_name}» أكبر من المتبقي القابل للإرجاع (".((float) $item->quantity - $returned).').']);
                }

                // POS lines saved before line nets were stored have no subtotal.
                $lineValue = (float) $item->subtotal > 0 ? (float) $item->subtotal : max(0, (float) $item->quantity * (float) $item->unit_price - (float) $item->discount);
                $unitNet = (float) $item->quantity > 0 ? $lineValue / (float) $item->quantity : (float) $item->unit_price;
                $lineNet = round($unitNet * $quantity, 2);
                $lineTax = round($lineNet * (float) $item->tax_rate / 100, 2);

                $noteLines[] = compact('item', 'quantity', 'lineNet', 'lineTax') + [
                    'return_to_stock' => (bool) ($line['return_to_stock'] ?? true),
                    'serials' => $line['serials'] ?? [],
                ];
            }

            if (! $noteLines) {
                throw ValidationException::withMessages(['lines' => 'حدد كمية مرتجعة واحدة على الأقل.']);
            }

            $subtotal = round(array_sum(array_column($noteLines, 'lineNet')), 2);
            $tax = round(array_sum(array_column($noteLines, 'lineTax')), 2);
            $total = round($subtotal + $tax, 2);
            $refundMethod = $data['refund_method'] ?? 'credit';
            $this->guardCredit($invoice, $total, $refundMethod);

            $note = CreditNote::create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'warehouse_id' => $warehouseId,
                'credit_note_number' => $this->numbers->next('credit_note', Carbon::parse($data['credit_note_date'])),
                'credit_note_date' => $data['credit_note_date'],
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'reason' => $data['reason'],
                'status' => $refundMethod === 'credit' ? 'approved' : 'refunded',
                'refund_method' => $refundMethod,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'kind' => 'return',
                'zatca_uuid' => (string) Str::uuid(),
                'currency_code' => $invoice->currency_code,
                'exchange_rate' => $invoice->exchange_rate ?: 1,
            ]);

            $returnedCost = 0.0;
            foreach ($noteLines as $l) {
                $note->items()->create([
                    'invoice_item_id' => $l['item']->id,
                    'product_id' => $l['item']->product_id,
                    'item_name' => $l['item']->item_name,
                    'quantity' => $l['quantity'],
                    'unit_price' => (float) $l['item']->unit_price,
                    'tax_rate' => (float) $l['item']->tax_rate,
                    'tax_amount' => $l['lineTax'],
                    'total' => round($l['lineNet'] + $l['lineTax'], 2),
                    'return_to_stock' => $l['return_to_stock'],
                    'vat_category' => $l['item']->vat_category,
                    'vat_exemption_code' => $l['item']->vat_exemption_code,
                ]);

                if ($l['item']->product_id && $l['return_to_stock']) {
                    $unitCost = $this->originalUnitCost($invoice, (int) $l['item']->product_id);
                    $this->stock->receive([
                        'product_id' => $l['item']->product_id,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $l['quantity'],
                        'unit_cost' => $unitCost,
                        'type' => 'sale_return',
                        'date' => $note->credit_note_date,
                        'source' => $note,
                        'reference' => $note->credit_note_number,
                        'serials' => $l['serials'],
                    ]);
                    $returnedCost += $unitCost * $l['quantity'];
                }
            }

            $this->settleInvoice($invoice, $total, $refundMethod);
            $this->poster->creditNote($note, [], $warehouseId, round($returnedCost, 2));
            $this->invoices->stampCreditNote($note);
            app(WebhookDispatcher::class)->dispatch('credit_note.created', [
                'id' => $note->id, 'number' => $note->credit_note_number, 'invoice_id' => $note->invoice_id, 'total' => (float) $note->total, 'reason' => $note->reason,
            ]);

            return $note;
        });
    }

    /**
     * A credit note can never credit more than the invoice, and cash can be
     * refunded only up to what the customer actually paid on it (an unpaid
     * part is credited to the customer's account instead).
     */
    public function guardCredit(Invoice $invoice, float $total, string $refundMethod): void
    {
        $credited = (float) CreditNote::where('invoice_id', $invoice->id)->whereIn('status', ['approved', 'refunded'])->sum('total');
        if ($total > round((float) $invoice->total - $credited, 2) + 0.01) {
            throw ValidationException::withMessages(['total' => 'قيمة الإشعار تتجاوز المتبقي من قيمة الفاتورة الأصلية (المتاح: '.number_format(max(0, (float) $invoice->total - $credited), 2).').']);
        }
        if ($refundMethod !== 'credit') {
            $refunded = (float) CreditNote::where('invoice_id', $invoice->id)->where('status', 'refunded')->where('refund_method', '!=', 'credit')->sum('total');
            $refundable = round((float) $invoice->paid_amount - $refunded, 2);
            if ($total > $refundable + 0.01) {
                throw ValidationException::withMessages(['refund_method' => 'لا يمكن رد مبلغ نقدي أكبر مما دفعه العميل على الفاتورة (المتاح للرد: '.number_format(max(0, $refundable), 2).'). اختر «خصم من رصيد العميل» للجزء غير المدفوع.']);
            }
        }
    }

    /**
     * A credit note reduces what the customer owes: first on the invoice
     * itself, the rest stays as credit on the customer's account (or is refunded).
     */
    public function settleInvoice(Invoice $invoice, float $amount, string $refundMethod): void
    {
        if ($refundMethod !== 'credit') {
            return;
        }

        $applied = min($amount, (float) $invoice->remaining_amount);
        if ($applied > 0) {
            $remaining = round((float) $invoice->remaining_amount - $applied, 2);
            $invoice->update([
                'remaining_amount' => $remaining,
                'payment_status' => $remaining <= 0 ? 'paid' : 'partially_paid',
            ]);
        }

        Customer::whereKey($invoice->customer_id)->decrement('current_balance', ExchangeRates::toBase($amount, $invoice->exchange_rate));
    }

    /**
     * Average unit cost at which the invoice's product left stock.
     */
    protected function originalUnitCost(Invoice $invoice, int $productId): float
    {
        $sources = array_filter([[$invoice->getMorphClass(), $invoice->id], $invoice->delivery_note_id ? [(new DeliveryNote)->getMorphClass(), $invoice->delivery_note_id] : null]);

        foreach ($sources as [$type, $id]) {
            $row = StockMovement::where('product_id', $productId)->where('source_type', $type)->where('source_id', $id)
                ->selectRaw('SUM(quantity) q, SUM(total_cost) c')->first();
            if ($row && (float) $row->q != 0.0) {
                return round((float) $row->c / (float) $row->q, 4);
            }
        }

        return (float) Product::find($productId)?->average_cost;
    }

    public function debitNote(array $data): DebitNote
    {
        return DB::transaction(function () use ($data) {
            $subtotal = round((float) $data['subtotal'], 2);
            $taxRate = (float) ($data['tax_rate'] ?? TaxProfile::rate());
            $tax = round($subtotal * $taxRate / 100, 2);
            // A debit note on an invoice is in that invoice's currency and rate; ZATCA requires the reference.
            $source = ! empty($data['invoice_id']) ? Invoice::find($data['invoice_id']) : null;
            if (! $source) {
                throw ValidationException::withMessages(['invoice_id' => 'اختر الفاتورة الأصلية التي يعدّلها الإشعار المدين.']);
            }

            $note = DebitNote::create([
                'currency_code' => $source?->currency_code,
                'exchange_rate' => $source?->exchange_rate ?: 1,
                'customer_id' => $data['customer_id'],
                'invoice_id' => $data['invoice_id'] ?? null,
                'debit_note_number' => $this->numbers->next('debit_note', Carbon::parse($data['debit_note_date'])),
                'debit_note_date' => $data['debit_note_date'],
                'reason' => $data['reason'],
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $tax,
                'total' => round($subtotal + $tax, 2),
                'status' => 'approved',
                'zatca_uuid' => (string) Str::uuid(),
                'created_by' => auth()->id(),
            ]);

            Customer::whereKey($note->customer_id)->increment('current_balance', ExchangeRates::toBase((float) $note->total, $note->exchange_rate));
            $this->poster->debitNote($note);
            app(EInvoiceManager::class)->stamp($note);

            return $note;
        });
    }

    /**
     * Allocate a customer receipt to invoices: explicit allocations, or
     * automatically to the oldest open invoices.
     */
    public function allocatePayment(CustomerPayment $payment, ?array $allocations = null): void
    {
        $remaining = (float) $payment->amount - (float) PaymentAllocation::where('customer_payment_id', $payment->id)->sum('amount');
        $exchangeDifference = 0.0;

        $targets = $allocations !== null
            ? collect($allocations)->filter(fn ($a) => (float) $a['amount'] > 0)
            : Invoice::where('customer_id', $payment->customer_id)->where('remaining_amount', '>', 0)
                ->orderBy('invoice_date')->orderBy('id')->get(['id', 'remaining_amount'])
                ->map(fn ($i) => ['invoice_id' => $i->id, 'amount' => (float) $i->remaining_amount]);

        foreach ($targets as $target) {
            if ($remaining <= 0.004) {
                break;
            }

            $invoice = Invoice::whereKey($target['invoice_id'])->lockForUpdate()->first();
            if (! $invoice || (int) $invoice->customer_id !== (int) $payment->customer_id) {
                throw ValidationException::withMessages(['allocations' => 'إحدى الفواتير لا تخص العميل.']);
            }
            if (($invoice->currency_code ?: null) !== ($payment->currency_code ?: null)) {
                if ($allocations === null) {
                    continue; // automatic allocation only settles same-currency invoices
                }
                throw ValidationException::withMessages(['allocations' => "عملة الفاتورة {$invoice->invoice_number} تختلف عن عملة السند."]);
            }

            $amount = round(min((float) $target['amount'], (float) $invoice->remaining_amount, $remaining), 2);
            if ($allocations !== null && (float) $target['amount'] > (float) $invoice->remaining_amount + 0.005) {
                throw ValidationException::withMessages(['allocations' => "المبلغ المخصص للفاتورة {$invoice->invoice_number} أكبر من المتبقي عليها."]);
            }
            if ($amount <= 0) {
                continue;
            }

            PaymentAllocation::create(['customer_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $amount]);
            // Settled at the payment rate, booked at the invoice rate.
            $exchangeDifference += ExchangeRates::toBase($amount, $payment->exchange_rate) - ExchangeRates::toBase($amount, $invoice->exchange_rate);

            $newRemaining = round((float) $invoice->remaining_amount - $amount, 2);
            $invoice->update([
                'paid_amount' => round((float) $invoice->paid_amount + $amount, 2),
                'remaining_amount' => $newRemaining,
                'payment_status' => $newRemaining <= 0 ? 'paid' : 'partially_paid',
            ]);

            $remaining -= $amount;
        }

        $exchangeDifference = round($exchangeDifference, 2);
        if ($exchangeDifference != 0.0) {
            $this->poster->exchangeDifference($payment, 'customer', (int) $payment->customer_id, $exchangeDifference, $payment->payment_date, $payment->payment_number);
            Customer::whereKey($payment->customer_id)->increment('current_balance', $exchangeDifference);
        }
    }
}

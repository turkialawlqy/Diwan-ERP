<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DeliveryNote;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentAllocation;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Inventory\StockService;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\Sales\SalesCycleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels posted documents without deleting them: the journal entries are
 * reversed, and everything the document moved (invoice balances, stock,
 * cheques, party balances) is put back. Tax documents (invoices, credit and
 * debit notes) are never cancelled: they are corrected with the opposite note.
 */
class DocumentCancellation
{
    /** Supplier payments recorded together with their bill are part of the bill's entry. */
    protected const BILL_PAYMENT_NOTE = 'سداد مرتبط بفاتورة الشراء';

    public function __construct(
        protected PostingService $posting,
        protected DocumentPoster $poster,
        protected StockService $stock,
    ) {}

    /** $chequeStatus: set when the voucher is undone because its cheque bounced. */
    public function customerPayment(CustomerPayment $payment, string $reason, ?string $chequeStatus = null): void
    {
        DB::transaction(function () use ($payment, $reason, $chequeStatus) {
            $payment = CustomerPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($payment, 'سند القبض');
            $this->releaseCheque($payment, $chequeStatus);

            $allocations = PaymentAllocation::where('customer_payment_id', $payment->id)->get();
            $parts = $allocations->isNotEmpty()
                ? $allocations->map(fn ($a) => [$a->invoice_id, (float) $a->amount])
                : collect($payment->invoice_id ? [[$payment->invoice_id, (float) $payment->amount]] : []);

            $exchangeDifference = 0.0;
            foreach ($parts as [$invoiceId, $amount]) {
                $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->first();
                if (! $invoice) {
                    continue;
                }
                $exchangeDifference += ExchangeRates::toBase($amount, $payment->exchange_rate) - ExchangeRates::toBase($amount, $invoice->exchange_rate);
                $this->reopen($invoice, $amount);
            }
            PaymentAllocation::where('customer_payment_id', $payment->id)->delete();

            Customer::whereKey($payment->customer_id)->increment('current_balance', round(ExchangeRates::toBase((float) $payment->amount, $payment->exchange_rate) - $exchangeDifference, 2));
            $this->finish($payment, $reason, 'إلغاء سند القبض '.$payment->payment_number);
        });
    }

    public function supplierPayment(SupplierPayment $payment, string $reason, ?string $chequeStatus = null): void
    {
        DB::transaction(function () use ($payment, $reason, $chequeStatus) {
            $payment = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($payment, 'سند الصرف');
            if (str_starts_with((string) $payment->notes, self::BILL_PAYMENT_NOTE)) {
                throw ValidationException::withMessages(['payment' => 'هذا السداد سُجّل مع فاتورة الشراء نفسها وقيده جزء من قيد الفاتورة؛ يُعالَج بمرتجع أو تسوية على الفاتورة.']);
            }
            $this->releaseCheque($payment, $chequeStatus);

            $difference = 0.0;
            if ($payment->purchase_invoice_id && ($invoice = PurchaseInvoice::whereKey($payment->purchase_invoice_id)->lockForUpdate()->first())) {
                $amount = (float) $payment->amount;
                $difference = ExchangeRates::toBase($amount, $invoice->exchange_rate) - ExchangeRates::toBase($amount, $payment->exchange_rate);
                $paid = max(0, round((float) $invoice->paid_amount - $amount, 2));
                $remaining = round((float) $invoice->remaining_amount + $amount, 2);
                $invoice->update(['paid_amount' => $paid, 'remaining_amount' => $remaining, 'payment_status' => $paid <= 0.004 ? 'unpaid' : 'partially_paid']);
            }

            Supplier::whereKey($payment->supplier_id)->increment('current_balance', round(ExchangeRates::toBase((float) $payment->amount, $payment->exchange_rate) + $difference, 2));
            $this->finish($payment, $reason, 'إلغاء سند الصرف '.$payment->payment_number);
        });
    }

    /** Goods come back into the warehouse at the cost they left with. */
    public function deliveryNote(DeliveryNote $note, string $reason): void
    {
        DB::transaction(function () use ($note, $reason) {
            $note = DeliveryNote::with('items')->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($note->status !== 'delivered' || $note->items->sum(fn ($i) => (float) $i->invoiced_quantity) > 0) {
                throw ValidationException::withMessages(['delivery' => $note->status === 'cancelled'
                    ? 'إذن التسليم ملغى مسبقاً.' : 'لا يمكن إلغاء إذن تسليم صدرت عليه فاتورة؛ استخدم مرتجع المبيعات.']);
            }

            $serials = $note->items->groupBy('product_id')->map(fn ($items) => $items->flatMap(fn ($i) => (array) ($i->serials ?? []))->all());
            foreach ($this->movementsOf($note) as $movement) {
                $this->stock->receive([
                    'product_id' => $movement->product_id, 'warehouse_id' => $movement->warehouse_id, 'quantity' => abs((float) $movement->quantity),
                    'unit_cost' => (float) $movement->unit_cost, 'batch_id' => $movement->batch_id, 'serials' => $serials[$movement->product_id] ?? [],
                    'type' => 'delivery_cancel', 'date' => now()->toDateString(), 'source' => $note, 'reference' => $note->delivery_number,
                ]);
            }

            foreach ($note->items->whereNotNull('sales_order_item_id') as $item) {
                SalesOrderItem::whereKey($item->sales_order_item_id)->decrement('delivered_quantity', (float) $item->quantity);
            }
            if ($note->sales_order_id && ($order = SalesOrder::find($note->sales_order_id))) {
                app(SalesCycleService::class)->refreshOrderStatus($order);
                if ((float) $order->items()->sum('delivered_quantity') <= 0 && $order->fresh()->status === 'partially_delivered') {
                    $order->update(['status' => 'confirmed']);
                }
            }

            $this->finish($note, $reason, 'إلغاء إذن التسليم '.$note->delivery_number, 'cancelled');
        });
    }

    /**
     * The received quantity leaves stock again. The receipt entry is reversed
     * at its value; when the stock ledger releases it at another cost
     * (average moved since), the difference is an inventory adjustment.
     */
    public function goodsReceipt(GoodsReceipt $receipt, string $reason): void
    {
        DB::transaction(function () use ($receipt, $reason) {
            $receipt = GoodsReceipt::with('items')->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== 'received' || $receipt->items->sum(fn ($i) => (float) $i->invoiced_quantity) > 0) {
                throw ValidationException::withMessages(['receipt' => $receipt->status === 'cancelled'
                    ? 'إذن الاستلام ملغى مسبقاً.' : 'لا يمكن إلغاء إذن استلام صدرت عليه فاتورة مورد؛ استخدم مرتجع المشتريات.']);
            }

            $serials = $receipt->items->groupBy('product_id')->map(fn ($items) => $items->flatMap(fn ($i) => (array) ($i->serials ?? []))->all());
            $released = 0.0;
            foreach ($this->movementsOf($receipt) as $movement) {
                $released += $this->stock->issue([
                    'product_id' => $movement->product_id, 'warehouse_id' => $movement->warehouse_id, 'quantity' => (float) $movement->quantity,
                    'batch_id' => $movement->batch_id, 'serials' => $serials[$movement->product_id] ?? [],
                    'type' => 'receipt_cancel', 'date' => now()->toDateString(), 'source' => $receipt, 'reference' => $receipt->receipt_number,
                ]);
            }

            foreach ($receipt->items->whereNotNull('purchase_order_item_id') as $item) {
                PurchaseOrderItem::whereKey($item->purchase_order_item_id)->decrement('received_quantity', (float) $item->quantity);
            }
            if ($receipt->purchase_order_id && ($order = PurchaseOrder::find($receipt->purchase_order_id))) {
                app(PurchaseCycleService::class)->refreshOrderStatus($order);
                if ((float) $order->items()->sum('received_quantity') <= 0 && $order->fresh()->status === 'partially_received') {
                    $order->update(['status' => 'approved']);
                }
            }

            $this->finish($receipt, $reason, 'إلغاء إذن الاستلام '.$receipt->receipt_number, 'cancelled');

            $difference = round((float) $receipt->total_value - $released, 2);
            if ($difference != 0.0) {
                $this->poster->stockAdjustment($receipt, $difference, now(), 'فرق تكلفة إلغاء إذن الاستلام '.$receipt->receipt_number);
            }
        });
    }

    /** A treasury transfer between two boxes (a journal entry with no document). */
    public function transfer(JournalEntry $entry, string $reason): void
    {
        DB::transaction(function () use ($entry, $reason) {
            $entry = JournalEntry::with('lines')->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->source_type !== 'transfer' || $entry->reversal_of_id) {
                abort(404);
            }
            if ($entry->status !== JournalEntry::STATUS_POSTED) {
                throw ValidationException::withMessages(['transfer' => 'التحويل ملغى مسبقاً.']);
            }

            foreach ($entry->lines as $line) {
                $box = BankAccount::where('account_id', $line->account_id)->first();
                if ($box) {
                    $box->decrement('current_balance', round((float) $line->debit - (float) $line->credit, 2));
                }
            }

            $this->posting->reverse($entry, null, 'إلغاء التحويل '.$entry->entry_number.' - '.$reason);
        });
    }

    protected function assertActive(Model $document, string $label): void
    {
        if ($document->status === 'cancelled') {
            throw ValidationException::withMessages(['document' => "{$label} ملغى مسبقاً."]);
        }
    }

    /**
     * A cheque still in hand is cancelled with its voucher; one already
     * deposited or cleared must be bounced first (a bounce of a deposited
     * cheque arrives here with $status = bounced).
     */
    protected function releaseCheque(Model $payment, ?string $status = null): void
    {
        if ($payment->payment_method !== 'cheque') {
            return;
        }

        $cheque = Cheque::where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->lockForUpdate()->first();
        if (! $cheque) {
            return;
        }
        $allowed = $status === 'bounced' ? ['pending', 'deposited'] : ['pending'];
        if (! in_array($cheque->status, $allowed, true)) {
            throw ValidationException::withMessages(['payment' => "الشيك رقم {$cheque->cheque_number} حالته «{$cheque->status}»؛ لا يُلغى السند إلا والشيك ما زال لدينا. سجّل ارتداد الشيك من وحدة الشيكات."]);
        }

        $status ??= 'cancelled';
        $cheque->update([
            'status' => $status,
            'history' => array_merge((array) $cheque->history, [['status' => $status, 'date' => now()->toDateString(), 'by' => auth()->id(), 'note' => $status === 'bounced' ? 'ارتداد الشيك وإلغاء سنده' : 'إلغاء السند']]),
        ]);
    }

    protected function reopen(Invoice $invoice, float $amount): void
    {
        $paid = max(0, round((float) $invoice->paid_amount - $amount, 2));
        $remaining = round((float) $invoice->remaining_amount + $amount, 2);
        $invoice->update(['paid_amount' => $paid, 'remaining_amount' => $remaining, 'payment_status' => $paid <= 0.004 ? 'unpaid' : 'partially_paid']);
    }

    protected function movementsOf(Model $document)
    {
        return StockMovement::where('source_type', $document->getMorphClass())->where('source_id', $document->id)->orderBy('id')->get();
    }

    protected function finish(Model $document, string $reason, string $description, string $status = 'cancelled'): void
    {
        $this->posting->reverseSource($document, $description.' - '.$reason);
        $document->update(['status' => $status, 'cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => mb_substr($reason, 0, 255)]);
    }
}

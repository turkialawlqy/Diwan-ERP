<?php

namespace App\Services\Purchases;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseInvoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Accounting\ExchangeRates;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\StockService;
use App\Services\Tax\TaxProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Editing / deleting a supplier bill entered directly, in branches without
 * government e-invoicing: its entry is reversed, the goods leave stock again
 * and the supplier balance and the payments recorded with it are undone.
 * Bills from receipts or purchase orders are corrected with a purchase return.
 */
class PurchaseInvoiceChanges
{
    /** Supplier payments recorded together with their bill (see PurchaseInvoiceController::store). */
    public const BILL_PAYMENT_NOTE = 'سداد مرتبط بفاتورة الشراء';

    protected const LINKS = [
        'purchase_returns' => 'مرتجع مشتريات',
        'landed_costs' => 'تكاليف شحن',
        'goods_receipts' => 'إذن استلام',
        'cheques' => 'شيك',
    ];

    public function __construct(protected PostingService $posting, protected StockService $stock) {}

    public function blocker(PurchaseInvoice $invoice): ?string
    {
        if (TaxProfile::forBranch($invoice->branch_id ? (int) $invoice->branch_id : null)->einvoicing()) {
            return 'فواتير الشراء في فروع الفوترة الإلكترونية لا تُعدَّل ولا تُحذف؛ سجّل مرتجع مشتريات.';
        }
        if ($invoice->source === 'grn' || $invoice->purchase_order_id) {
            return 'الفاتورة صادرة من أمر شراء أو استلامات؛ صحّحها بمرتجع مشتريات.';
        }
        foreach (self::LINKS as $table => $label) {
            if (Schema::hasColumn($table, 'purchase_invoice_id') && DB::table($table)->where('purchase_invoice_id', $invoice->id)->exists()) {
                return "على الفاتورة {$label}؛ احذفه أو ألغه أولاً.";
            }
        }
        if (SupplierPayment::where('purchase_invoice_id', $invoice->id)->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', self::BILL_PAYMENT_NOTE.'%'))->exists()) {
            return 'على الفاتورة سند صرف لاحق؛ ألغه أولاً.';
        }

        return null;
    }

    public function assertChangeable(PurchaseInvoice $invoice): void
    {
        if ($reason = $this->blocker($invoice)) {
            throw ValidationException::withMessages(['invoice' => $reason]);
        }
    }

    /** Undo the bill (in the caller's transaction); the record itself stays for an edit. */
    public function undo(PurchaseInvoice $invoice, string $description): void
    {
        $this->assertChangeable($invoice);
        $this->posting->reverseSource($invoice, $description);

        // Goods still held from this bill leave the warehouse again, on the bill's own date
        // (like its reversed entry) while that period is open.
        $date = app(FiscalCalendar::class)->reversalDate($invoice->invoice_date, $invoice->company_id);
        StockMovement::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->get()
            ->groupBy(fn ($m) => $m->product_id.'|'.$m->warehouse_id.'|'.$m->batch_id)
            ->each(function ($moves) use ($invoice, $date) {
                $net = round($moves->sum(fn ($m) => (float) $m->quantity), 4);
                if ($net <= 0) {
                    return;
                }
                $first = $moves->first();
                $this->stock->issue([
                    'product_id' => $first->product_id, 'warehouse_id' => $first->warehouse_id, 'batch_id' => $first->batch_id, 'quantity' => $net,
                    'serials' => $this->serialsStillHeld($invoice, $first->product_id, $first->warehouse_id, $net),
                    'type' => 'purchase_cancel', 'date' => $date, 'source' => $invoice, 'reference' => $invoice->invoice_number,
                ]);
            });

        if ((float) $invoice->remaining_amount > 0) {
            Supplier::whereKey($invoice->supplier_id)->decrement('current_balance', ExchangeRates::toBase((float) $invoice->remaining_amount, (float) ($invoice->exchange_rate ?: 1)));
        }
        SupplierPayment::where('purchase_invoice_id', $invoice->id)->delete();
        $invoice->items()->delete();
    }

    /** @return list<string> the serial numbers this bill brought in, when all of them are still in the warehouse */
    protected function serialsStillHeld(PurchaseInvoice $invoice, int $productId, int $warehouseId, float $quantity): array
    {
        if (Product::whereKey($productId)->value('tracking') !== 'serial') {
            return [];
        }
        $serials = ProductSerial::where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('status', 'in_stock')
            ->where('last_source_type', $invoice->getMorphClass())->where('last_source_id', $invoice->id)->pluck('serial_number')->all();
        if (count($serials) !== (int) round($quantity)) {
            throw ValidationException::withMessages(['invoice' => 'خرجت بعض الأرقام التسلسلية لهذه الفاتورة من المستودع؛ صحّحها بمرتجع مشتريات.']);
        }

        return $serials;
    }

    public function delete(PurchaseInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->undo($invoice, 'حذف فاتورة الشراء '.$invoice->invoice_number);
            $invoice->delete();
        });
    }
}

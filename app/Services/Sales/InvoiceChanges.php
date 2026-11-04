<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\StockMovement;
use App\Services\Accounting\ExchangeRates;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\StockService;
use App\Services\Tax\TaxProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Editing and deleting sales invoices where the law allows it: branches whose
 * country has no government e-invoicing (ZATCA invoices are corrected with notes
 * only). An invoice is changed by undoing everything it did (ledger entry
 * reversed, stock returned, customer balance) and, for an edit, issuing it again
 * on the same record and number.
 */
class InvoiceChanges
{
    /** Documents that point at an invoice; any of them makes it unchangeable. */
    protected const LINKS = [
        'credit_notes' => 'إشعار دائن / مرتجع',
        'debit_notes' => 'إشعار مدين',
        'customer_payments' => 'سند قبض',
        'payment_allocations' => 'سند قبض',
        'cheques' => 'شيك',
        'pos_orders' => 'طلب نقطة بيع',
        'progress_billings' => 'مستخلص مشروع',
        'equipment_billings' => 'فوترة معدات',
        'bookings' => 'حجز',
        'work_orders' => 'أمر عمل',
        'customer_packages' => 'باقة زيارات',
        'recurring_invoice_runs' => 'فاتورة دورية / اشتراك (فترة مفوترة)',
        'tickets' => 'تذكرة دعم',
    ];

    public function __construct(protected PostingService $posting, protected StockService $stock, protected InvoiceService $invoices) {}

    /** Why this invoice cannot be edited or deleted, or null when it can. */
    public function blocker(Invoice $invoice): ?string
    {
        if (TaxProfile::forBranch($invoice->branch_id ? (int) $invoice->branch_id : null)->einvoicing()) {
            return 'فواتير هذا الفرع خاضعة للفوترة الإلكترونية ولا تُعدَّل ولا تُحذف؛ صحّحها بإشعار دائن أو مدين.';
        }
        if ($invoice->customer_subscription_id) {
            return 'الفاتورة صادرة من اشتراك عميل؛ صحّحها بإشعار دائن.';
        }
        if ($invoice->sales_order_id || $invoice->delivery_note_id) {
            return 'الفاتورة صادرة من أمر بيع أو إذن تسليم؛ صحّحها بإشعار دائن.';
        }
        foreach (self::LINKS as $table => $label) {
            if (Schema::hasColumn($table, 'invoice_id') && DB::table($table)->where('invoice_id', $invoice->id)->exists()) {
                return "على الفاتورة {$label}؛ احذفه أو ألغه أولاً.";
            }
        }

        return null;
    }

    public function assertChangeable(Invoice $invoice): void
    {
        if ($reason = $this->blocker($invoice)) {
            throw ValidationException::withMessages(['invoice' => $reason]);
        }
    }

    public function delete(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertChangeable($invoice);
            $this->undo($invoice, 'حذف الفاتورة '.$invoice->invoice_number);
            Quotation::where('converted_invoice_id', $invoice->id)->update(['converted_invoice_id' => null, 'status' => 'accepted']);
            $invoice->items()->delete();
            $invoice->delete();
        });
    }

    public function update(Invoice $invoice, array $data, array $lines): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $lines) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertChangeable($invoice);
            $this->undo($invoice, 'تعديل الفاتورة '.$invoice->invoice_number);
            $invoice->items()->delete();

            return $this->invoices->create($data, $lines, ['replace' => $invoice]);
        });
    }

    /** Put back everything the invoice moved. */
    protected function undo(Invoice $invoice, string $description): void
    {
        $this->posting->reverseSource($invoice, $description);

        // Net quantity still out of stock per product / warehouse / batch (earlier edits returned theirs).
        // Returned on the invoice's own date (like its reversed entry) while that period is open.
        $date = app(FiscalCalendar::class)->reversalDate($invoice->invoice_date, $invoice->company_id);
        StockMovement::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->get()
            ->groupBy(fn ($m) => $m->product_id.'|'.$m->warehouse_id.'|'.$m->batch_id)
            ->each(function ($moves) use ($invoice, $date) {
                $net = round($moves->sum(fn ($m) => (float) $m->quantity), 4);
                if ($net >= 0) {
                    return;
                }
                $out = $moves->filter(fn ($m) => (float) $m->quantity < 0);
                $qty = abs($out->sum(fn ($m) => (float) $m->quantity));
                $first = $moves->first();
                $this->stock->receive([
                    'product_id' => $first->product_id, 'warehouse_id' => $first->warehouse_id, 'batch_id' => $first->batch_id,
                    'quantity' => abs($net), 'unit_cost' => $qty > 0 ? abs($out->sum(fn ($m) => (float) $m->total_cost)) / $qty : 0,
                    'type' => 'sale_cancel', 'date' => $date, 'source' => $invoice, 'reference' => $invoice->invoice_number,
                ]);
            });

        if ((float) $invoice->remaining_amount > 0) {
            Customer::whereKey($invoice->customer_id)->decrement('current_balance', ExchangeRates::toBase((float) $invoice->remaining_amount, (float) ($invoice->exchange_rate ?: 1)));
        }
    }
}

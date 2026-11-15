<?php

namespace App\Services\Purchases;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseDraft;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Notifications\ErpAlert;
use App\Services\Accounting\DocumentCancellation;
use App\Services\Documents\DocumentNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The warehouse keeper's stock-in and its purchase draft.
 *
 *  1. createFromStockIn: the keeper gives items and quantities (and batch / serials where the item needs them). The stock
 *     enters at once on a goods receipt valued at a provisional cost (the item's purchase price), and a draft opens.
 *  2. complete: accounting adds the supplier, the date and the real prices; the goods receipt becomes a supplier invoice
 *     through the normal three-way-match path (price differences go to the stock value), and the draft closes.
 *  3. discard: a wrong stock-in is cancelled — the receipt leaves stock again — while it has no invoice.
 */
class PurchaseDraftService
{
    public function __construct(protected PurchaseCycleService $cycle, protected DocumentNumberService $numbers) {}

    /**
     * @param  array{warehouse_id:int, receipt_date:string, notes?:?string, supplier_delivery_number?:?string}  $data
     * @param  array<int, array{product_id:int, quantity:float|string, batch_number?:?string, expiry_date?:?string, serials?:array}>  $lines
     */
    public function createFromStockIn(array $data, array $lines): PurchaseDraft
    {
        return DB::transaction(function () use ($data, $lines) {
            $products = Product::whereIn('id', collect($lines)->pluck('product_id'))->get()->keyBy('id');
            $receiptLines = [];
            foreach ($lines as $line) {
                $product = $products[$line['product_id']] ?? null;
                if (! $product || $product->type === 'service' || $product->is_kit) {
                    $name = $product->name_ar ?? '';
                    throw ValidationException::withMessages(['lines' => "الصنف «{$name}» ليس صنفًا مخزنيًا فلا يدخل المستودع."]);
                }
                $receiptLines[] = [
                    'product_id' => $product->id,
                    'item_name' => $product->name_ar,
                    'quantity' => (float) $line['quantity'],
                    // Provisional: the price it was last bought at; accounting corrects it when the invoice is written.
                    'unit_cost' => (float) ($product->purchase_price ?: $product->average_cost ?: 0),
                    'batch_number' => $line['batch_number'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'serials' => $line['serials'] ?? [],
                ];
            }

            $receipt = $this->cycle->receive(null, [
                'warehouse_id' => $data['warehouse_id'],
                'receipt_date' => $data['receipt_date'],
                'notes' => $data['notes'] ?? null,
                'supplier_delivery_number' => $data['supplier_delivery_number'] ?? null,
            ], $receiptLines);

            $draft = PurchaseDraft::create([
                'branch_id' => $receipt->branch_id,
                'number' => $this->numbers->next('purchase_draft', Carbon::parse($data['receipt_date'])),
                'goods_receipt_id' => $receipt->id,
                'warehouse_id' => $receipt->warehouse_id,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $this->notifyAccounting($draft);

            return $draft;
        });
    }

    /**
     * @param  array{supplier_id:int, supplier_invoice_number:string, invoice_date:string, due_date?:?string, payment_method:string, payment_status:string, paid_amount?:mixed, bank_account_id?:mixed, notes?:?string}  $data
     * @param  array<int, array{goods_receipt_item_id:int, quantity:float|string, unit_price:float|string, discount?:mixed, tax_rate?:mixed}>  $lines
     */
    public function complete(PurchaseDraft $draft, array $data, array $lines): PurchaseInvoice
    {
        return DB::transaction(function () use ($draft, $data, $lines) {
            $draft = PurchaseDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($draft->status !== 'pending') {
                throw ValidationException::withMessages(['draft' => $draft->status === 'completed' ? 'المسودة فُوترت مسبقًا.' : 'المسودة أُلغيت.']);
            }
            $receipt = GoodsReceipt::with('items')->whereKey($draft->goods_receipt_id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== 'received') {
                throw ValidationException::withMessages(['draft' => 'إذن الاستلام لم يعد قابلًا للفوترة.']);
            }
            // Every line must belong to this draft's receipt.
            $own = $receipt->items->pluck('id')->all();
            foreach ($lines as $line) {
                if (! in_array((int) $line['goods_receipt_item_id'], $own, true)) {
                    throw ValidationException::withMessages(['lines' => 'أحد البنود لا يخص هذه المسودة.']);
                }
            }

            // The supplier is known now: the receipt and its "goods received not invoiced" line carry it.
            $receipt->update(['supplier_id' => $data['supplier_id']]);
            $entries = DB::table('journal_entries')->where('company_id', $receipt->company_id)->where('source_type', $receipt->getMorphClass())->where('source_id', $receipt->id)->pluck('id');
            if ($entries->isNotEmpty()) {
                DB::table('journal_entry_lines')->whereIn('journal_entry_id', $entries)->where('credit', '>', 0)->whereNull('party_id')
                    ->update(['party_type' => 'supplier', 'party_id' => $data['supplier_id']]);
            }

            $invoice = $this->cycle->invoiceReceipts($data + ['accept_price_variance' => true], $lines);
            $draft->update(['status' => 'completed', 'purchase_invoice_id' => $invoice->id, 'completed_by' => auth()->id(), 'completed_at' => now()]);

            return $invoice;
        });
    }

    /** A wrong stock-in: the received quantity leaves stock again and the draft is closed. */
    public function discard(PurchaseDraft $draft, string $reason): void
    {
        DB::transaction(function () use ($draft, $reason) {
            $draft = PurchaseDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($draft->status !== 'pending') {
                throw ValidationException::withMessages(['draft' => 'لا تُحذف إلا المسودة التي بانتظار الفوترة.']);
            }
            app(DocumentCancellation::class)->goodsReceipt($draft->receipt, $reason);
            $draft->update(['status' => 'cancelled']);
        });
    }

    protected function notifyAccounting(PurchaseDraft $draft): void
    {
        $url = route('purchases.drafts.index', [], false);
        User::where('company_id', $draft->company_id)->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->id !== auth()->id() && $u->can('purchases.drafts.complete'))
            ->each(fn (User $u) => $u->notify(new ErpAlert('purchase_draft', 'مواد دخلت المستودع بانتظار الفاتورة', "{$draft->number}: أضف المورد والتاريخ والأسعار لإكمال فاتورة المشتريات", $url)));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\PurchaseDraft;
use App\Rules\BranchBox;
use App\Services\AttachmentService;
use App\Services\Purchases\PurchaseDraftService;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Accounting's side of a stock-in: complete the draft (supplier, date, prices) into a supplier invoice, or discard it. */
class PurchaseDraftController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status', 'pending');

        return Inertia::render('Purchases/Drafts/Index', [
            'status' => $status,
            'drafts' => PurchaseDraft::with(['warehouse:id,name_ar', 'creator:id,name', 'receipt:id,receipt_date,total_value', 'invoice:id,invoice_number'])
                ->when(in_array($status, ['pending', 'completed', 'cancelled'], true), fn ($q) => $q->where('status', $status))
                ->latest('id')->paginate($this->perPage($request))->withQueryString()
                ->through(fn ($d) => [
                    'id' => $d->id, 'number' => $d->number, 'status' => $d->status, 'warehouse' => $d->warehouse?->name_ar, 'by' => $d->creator?->name,
                    'date' => $d->receipt?->receipt_date?->toDateString(), 'value' => (float) ($d->receipt?->total_value ?? 0),
                    'invoice_number' => $d->invoice?->invoice_number, 'invoice_id' => $d->purchase_invoice_id,
                ]),
            'pending' => PurchaseDraft::where('status', 'pending')->count(),
        ]);
    }

    public function completeForm(PurchaseDraft $draft)
    {
        abort_unless($draft->status === 'pending', 404);
        $draft->load(['receipt.items', 'warehouse:id,name_ar']);

        return Inertia::render('Purchases/Drafts/Complete', [
            'draft' => ['id' => $draft->id, 'number' => $draft->number, 'warehouse' => $draft->warehouse?->name_ar, 'date' => $draft->receipt->receipt_date->toDateString(), 'notes' => $draft->notes],
            'items' => $draft->receipt->items->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->item_name, 'quantity' => (float) $i->quantity, 'provisional_cost' => (float) $i->unit_cost, 'tax_rate' => TaxProfile::rate(),
            ])->values(),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    public function complete(Request $request, PurchaseDraft $draft, PurchaseDraftService $drafts)
    {
        $data = $request->validate([
            'supplier_id' => 'required|tenant_exists:suppliers,id',
            'supplier_invoice_number' => 'required|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'payment_method' => 'required|in:cash,card,bank_transfer,credit',
            'payment_status' => 'required|in:paid,partially_paid,unpaid',
            'paid_amount' => 'nullable|numeric|min:0',
            // The bill is filed in the top bar's branch (PurchaseCycleService::invoiceReceipts).
            'bank_account_id' => ['nullable', BranchBox::of()],
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:500',
            'lines.*.goods_receipt_item_id' => 'required|tenant_exists:goods_receipt_items,id',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $invoice = $drafts->complete($draft, $data, $data['lines']);
        app(AttachmentService::class)->claim($request, $invoice, 'purchase-invoice');

        return redirect()->route('purchases.invoices.show', $invoice->id)->with('success', "تمت فوترة المسودة {$draft->number}: فاتورة المورد {$invoice->invoice_number}");
    }

    public function destroy(Request $request, PurchaseDraft $draft, PurchaseDraftService $drafts)
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:300'])['reason'] ?? 'مسودة إدخال مستودع ملغاة';
        $drafts->discard($draft, $reason);

        return redirect()->route('purchases.drafts.index')->with('success', 'حُذفت المسودة وخرجت الكميات من المستودع');
    }
}

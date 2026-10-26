<?php

namespace App\Http\Controllers;

use App\Models\CustomerPayment;
use App\Models\DeliveryNote;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\SupplierPayment;
use App\Services\Accounting\DocumentCancellation;
use Illuminate\Http\Request;

/**
 * Cancel posted vouchers and stock documents with a reversal entry.
 */
class DocumentCancellationController extends Controller
{
    public function __construct(protected DocumentCancellation $cancellation) {}

    public function customerPayment(Request $request, CustomerPayment $payment)
    {
        $this->cancellation->customerPayment($payment, $this->reason($request));

        return back()->with('success', "تم إلغاء سند القبض {$payment->payment_number} وعكس قيده");
    }

    public function supplierPayment(Request $request, SupplierPayment $payment)
    {
        $this->cancellation->supplierPayment($payment, $this->reason($request));

        return back()->with('success', "تم إلغاء سند الصرف {$payment->payment_number} وعكس قيده");
    }

    public function deliveryNote(Request $request, DeliveryNote $delivery)
    {
        $this->cancellation->deliveryNote($delivery, $this->reason($request));

        return back()->with('success', "تم إلغاء إذن التسليم {$delivery->delivery_number} وإعادة البضاعة للمستودع");
    }

    public function goodsReceipt(Request $request, GoodsReceipt $receipt)
    {
        $this->cancellation->goodsReceipt($receipt, $this->reason($request));

        return back()->with('success', "تم إلغاء إذن الاستلام {$receipt->receipt_number} وإخراج البضاعة من المستودع");
    }

    public function transfer(Request $request, JournalEntry $entry)
    {
        $this->cancellation->transfer($entry, $this->reason($request));

        return back()->with('success', "تم إلغاء التحويل {$entry->entry_number}");
    }

    protected function reason(Request $request): string
    {
        return $request->validate(['reason' => 'required|string|max:255'], [], ['reason' => 'سبب الإلغاء'])['reason'];
    }
}

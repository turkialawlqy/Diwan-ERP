<?php

namespace App\Services\Purchases;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\ExchangeRates;
use Illuminate\Validation\ValidationException;

/**
 * Settles purchase invoices with one supplier payment: the amounts given per invoice, or automatically the oldest
 * open invoices first. Whatever is left stays on the supplier's account as an advance. Undone by release().
 */
class SupplierPaymentAllocator
{
    public function __construct(protected DocumentPoster $poster) {}

    /** @param array<int, array{purchase_invoice_id: int, amount: float}>|null $allocations null = oldest first */
    public function allocate(SupplierPayment $payment, ?array $allocations = null): void
    {
        $payment = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
        $remaining = (float) $payment->amount - (float) SupplierPaymentAllocation::where('supplier_payment_id', $payment->id)->sum('amount');
        $exchangeDifference = 0.0;

        $targets = $allocations !== null
            ? collect($allocations)->filter(fn ($a) => (float) $a['amount'] > 0)
            : PurchaseInvoice::where('supplier_id', $payment->supplier_id)->where('remaining_amount', '>', 0)
                ->orderBy('invoice_date')->orderBy('id')->get(['id', 'remaining_amount'])
                ->map(fn ($i) => ['purchase_invoice_id' => $i->id, 'amount' => (float) $i->remaining_amount]);

        foreach ($targets as $target) {
            if ($remaining <= 0.004) {
                break;
            }
            $invoice = PurchaseInvoice::whereKey($target['purchase_invoice_id'])->lockForUpdate()->first();
            if (! $invoice || (int) $invoice->supplier_id !== (int) $payment->supplier_id) {
                throw ValidationException::withMessages(['allocations' => 'إحدى الفواتير لا تخص المورد.']);
            }
            if (($invoice->currency_code ?: null) !== ($payment->currency_code ?: null)) {
                if ($allocations === null) {
                    continue; // automatic allocation only settles same-currency invoices
                }
                throw ValidationException::withMessages(['allocations' => "عملة الفاتورة {$invoice->invoice_number} تختلف عن عملة السند."]);
            }
            if ($allocations !== null && (float) $target['amount'] > (float) $invoice->remaining_amount + 0.005) {
                throw ValidationException::withMessages(['allocations' => "المبلغ المخصص للفاتورة {$invoice->invoice_number} أكبر من المتبقي عليها."]);
            }
            $amount = round(min((float) $target['amount'], (float) $invoice->remaining_amount, $remaining), 2);
            if ($amount <= 0) {
                continue;
            }

            SupplierPaymentAllocation::create(['supplier_payment_id' => $payment->id, 'purchase_invoice_id' => $invoice->id, 'amount' => $amount]);
            // Paid at the payment rate, booked at the bill rate: for a supplier a higher payment rate is a loss.
            $exchangeDifference += ExchangeRates::toBase($amount, $invoice->exchange_rate) - ExchangeRates::toBase($amount, $payment->exchange_rate);

            $newRemaining = round((float) $invoice->remaining_amount - $amount, 2);
            $invoice->update([
                'paid_amount' => round((float) $invoice->paid_amount + $amount, 2),
                'remaining_amount' => $newRemaining,
                'payment_status' => $newRemaining <= 0.004 ? 'paid' : 'partially_paid',
            ]);
            $remaining -= $amount;
        }

        $exchangeDifference = round($exchangeDifference, 2);
        if ($exchangeDifference != 0.0 && $payment->currency_code) {
            $this->poster->exchangeDifference($payment, 'supplier', (int) $payment->supplier_id, $exchangeDifference, $payment->payment_date, $payment->payment_number);
            Supplier::whereKey($payment->supplier_id)->decrement('current_balance', $exchangeDifference);
        }
    }

    /**
     * Give back what a cancelled payment settled: invoices reopen, the exchange difference reverses.
     *
     * @return float the base-currency difference to add back to the supplier's balance
     */
    public function release(SupplierPayment $payment): float
    {
        $difference = 0.0;
        foreach (SupplierPaymentAllocation::where('supplier_payment_id', $payment->id)->get() as $allocation) {
            $invoice = PurchaseInvoice::whereKey($allocation->purchase_invoice_id)->lockForUpdate()->first();
            if (! $invoice) {
                continue;
            }
            $amount = (float) $allocation->amount;
            $difference += ExchangeRates::toBase($amount, $invoice->exchange_rate) - ExchangeRates::toBase($amount, $payment->exchange_rate);
            $paid = max(0, round((float) $invoice->paid_amount - $amount, 2));
            $invoice->update(['paid_amount' => $paid, 'remaining_amount' => round((float) $invoice->remaining_amount + $amount, 2), 'payment_status' => $paid <= 0.004 ? 'unpaid' : 'partially_paid']);
        }
        SupplierPaymentAllocation::where('supplier_payment_id', $payment->id)->delete();

        return round($difference, 2);
    }
}

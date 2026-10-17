<?php

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DebitNote;
use App\Models\PaymentAllocation;
use App\Services\Accounting\ExchangeRates;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Customer account statement with a running balance. Shared by the statement
 * screen, its PDF and the customer's signed portal link so all three agree.
 *
 * Debits: invoices, debit notes. Credits: payments (including amounts paid at
 * checkout), credit notes that reduce the receivable (not cash refunds).
 */
class CustomerStatement
{
    /**
     * @return array{transactions: Collection, opening: float, closing: float}
     */
    public function build(Customer $customer, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $customer->load([
            'invoices' => fn ($q) => $q->orderBy('invoice_date'),
            'payments' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('payment_date'),
        ]);

        $rows = collect();
        $day = fn ($date, $fallback = null) => ($date ?? $fallback ?? now())->format('Y-m-d');

        // All amounts in base currency; foreign documents note their own amount.
        $base = fn ($doc, $amount) => ExchangeRates::toBase((float) $amount, $doc->exchange_rate);
        $note = fn ($doc, $amount, $text) => $doc->currency_code ? $text.' ('.number_format((float) $amount, 2).' '.$doc->currency_code.')' : $text;

        // What receipts paid on each invoice: their allocations, plus older
        // receipts linked to the invoice without allocation rows.
        $allocated = PaymentAllocation::whereIn('customer_payment_id', $customer->payments->pluck('id'))->get(['customer_payment_id', 'invoice_id', 'amount']);
        $withAllocations = $allocated->pluck('customer_payment_id')->unique();
        $paidByReceipts = fn ($invoiceId) => (float) $allocated->where('invoice_id', $invoiceId)->sum('amount')
            + (float) $customer->payments->where('invoice_id', $invoiceId)->whereNotIn('id', $withAllocations)->sum('amount');

        foreach ($customer->invoices as $inv) {
            $date = $day($inv->invoice_date, $inv->created_at);
            $rows->push($this->row($date, 1, 'invoice', 'فاتورة ضريبية', $inv->invoice_number, $base($inv, $inv->total), 0, $note($inv, $inv->total, $inv->notes ?: "فاتورة مبيعات {$inv->invoice_number}"), ['invoice_id' => $inv->id]));

            // Amounts paid at checkout are not in customer_payments.
            $immediate = max(0, round((float) $inv->paid_amount - $paidByReceipts($inv->id), 2));
            if ($immediate > 0) {
                $rows->push($this->row($date, 2, 'payment', 'سداد فوري للفاتورة', "سداد {$inv->invoice_number}", 0, $base($inv, $immediate), 'سداد عند إصدار الفاتورة ('.$this->method($inv->payment_method).')'));
            }
        }

        $invoiceRates = $customer->invoices->pluck('exchange_rate', 'id');
        foreach ($customer->payments as $pmt) {
            $date = $day($pmt->payment_date, $pmt->created_at);
            $rows->push($this->row($date, 2, 'payment', 'سند قبض', $pmt->payment_number ?: "PAY-{$pmt->id}", 0, $base($pmt, $pmt->amount), $note($pmt, $pmt->amount, $pmt->notes ?: 'سند قبض عبر '.$this->method($pmt->payment_method))));

            // Realised exchange difference on what the payment settled.
            if ($pmt->currency_code) {
                $difference = round(PaymentAllocation::where('customer_payment_id', $pmt->id)->get()
                    ->sum(fn ($a) => ExchangeRates::toBase((float) $a->amount, $pmt->exchange_rate) - ExchangeRates::toBase((float) $a->amount, $invoiceRates[$a->invoice_id] ?? 1)), 2);
                if ($difference != 0.0) {
                    $rows->push($this->row($date, 3, 'fx', 'فرق عملة', $pmt->payment_number, max($difference, 0), max(-$difference, 0), $difference > 0 ? 'أرباح فروق عملة' : 'خسائر فروق عملة'));
                }
            }
        }

        CreditNote::where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('status', 'approved')
                ->orWhere(fn ($r) => $r->where('status', 'refunded')->where(fn ($m) => $m->whereNull('refund_method')->orWhere('refund_method', 'credit'))))
            ->get()
            ->each(fn ($n) => $rows->push($this->row($day($n->credit_note_date, $n->created_at), 3, 'credit_note', 'إشعار دائن', $n->credit_note_number, 0, $base($n, $n->total), $note($n, $n->total, $n->reason ?: 'مرتجع / تخفيض'))));

        DebitNote::where('customer_id', $customer->id)->where('status', '!=', 'draft')->get()
            ->each(fn ($n) => $rows->push($this->row($day($n->debit_note_date, $n->created_at), 1, 'debit_note', 'إشعار مدين', $n->debit_note_number, $base($n, $n->total), 0, $note($n, $n->total, $n->reason ?: 'إشعار مدين'))));

        $rows = $rows->sortBy(fn ($r) => $r['date'].'_'.$r['order_key'])->values();

        $opening = (float) ($customer->opening_balance ?? 0);
        // Movements before the period roll into the opening balance.
        if ($from) {
            $before = $rows->filter(fn ($r) => $r['date'] < $from->toDateString());
            $opening += $before->sum('debit') - $before->sum('credit');
            $rows = $rows->reject(fn ($r) => $r['date'] < $from->toDateString())->values();
        }
        if ($to) {
            $rows = $rows->reject(fn ($r) => $r['date'] > $to->toDateString())->values();
        }

        $balance = $opening;
        $transactions = collect();
        if ($opening != 0 || $rows->isEmpty()) {
            $transactions->push($this->row($from?->toDateString() ?? ($rows->first()['date'] ?? $day($customer->created_at)), 0, 'opening_balance', 'رصيد افتتاحي', 'افتتاحي', max($opening, 0), max(-$opening, 0), 'الرصيد الافتتاحي') + ['balance' => round($opening, 2)]);
        }
        foreach ($rows as $row) {
            $balance += $row['debit'] - $row['credit'];
            $transactions->push($row + ['balance' => round($balance, 2)]);
        }

        return ['transactions' => $transactions, 'opening' => round($opening, 2), 'closing' => round($balance, 2)];
    }

    protected function row(string $date, int $order, string $type, string $label, string $reference, float $debit, float $credit, string $notes, array $extra = []): array
    {
        return ['date' => $date, 'order_key' => $order, 'type' => $type, 'type_ar' => $label, 'reference' => $reference, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'notes' => $notes] + $extra;
    }

    protected function method(?string $method): string
    {
        return match ($method) {
            'cash' => 'نقداً',
            'card' => 'بطاقة/مدى',
            default => 'تحويل',
        };
    }
}

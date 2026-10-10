<?php

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DebitNote;
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
            'payments' => fn ($q) => $q->orderBy('payment_date'),
        ]);

        $rows = collect();
        $day = fn ($date, $fallback = null) => ($date ?? $fallback ?? now())->format('Y-m-d');

        foreach ($customer->invoices as $inv) {
            $date = $day($inv->invoice_date, $inv->created_at);
            $rows->push($this->row($date, 1, 'invoice', 'فاتورة ضريبية', $inv->invoice_number, (float) $inv->total, 0, $inv->notes ?: "فاتورة مبيعات {$inv->invoice_number}", ['invoice_id' => $inv->id]));

            // Amounts paid at checkout are not in customer_payments.
            $linked = (float) $customer->payments->where('invoice_id', $inv->id)->sum('amount');
            $immediate = max(0, (float) $inv->paid_amount - $linked);
            if ($immediate > 0) {
                $rows->push($this->row($date, 2, 'payment', 'سداد فوري للفاتورة', "سداد {$inv->invoice_number}", 0, $immediate, 'سداد عند إصدار الفاتورة ('.$this->method($inv->payment_method).')'));
            }
        }

        foreach ($customer->payments as $pmt) {
            $rows->push($this->row($day($pmt->payment_date, $pmt->created_at), 2, 'payment', 'سند قبض', $pmt->payment_number ?: "PAY-{$pmt->id}", 0, (float) $pmt->amount, $pmt->notes ?: 'سند قبض عبر '.$this->method($pmt->payment_method)));
        }

        CreditNote::where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('status', 'approved')
                ->orWhere(fn ($r) => $r->where('status', 'refunded')->where(fn ($m) => $m->whereNull('refund_method')->orWhere('refund_method', 'credit'))))
            ->get()
            ->each(fn ($n) => $rows->push($this->row($day($n->credit_note_date, $n->created_at), 3, 'credit_note', 'إشعار دائن', $n->credit_note_number, 0, (float) $n->total, $n->reason ?: 'مرتجع / تخفيض')));

        DebitNote::where('customer_id', $customer->id)->where('status', '!=', 'draft')->get()
            ->each(fn ($n) => $rows->push($this->row($day($n->debit_note_date, $n->created_at), 1, 'debit_note', 'إشعار مدين', $n->debit_note_number, (float) $n->total, 0, $n->reason ?: 'إشعار مدين')));

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

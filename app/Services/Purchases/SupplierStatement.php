<?php

namespace App\Services\Purchases;

use App\Models\JournalEntryLine;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\ExchangeRates;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Supplier account statement with a running balance (what we owe him). Credits: purchase invoices, manual
 * entries crediting his account. Debits: payments, purchase returns settled on account, manual entries debiting it.
 */
class SupplierStatement
{
    /** @return array{transactions: Collection, opening: float, closing: float} */
    public function build(Supplier $supplier, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $supplier->load([
            'purchaseInvoices' => fn ($q) => $q->orderBy('invoice_date'),
            'payments' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('payment_date'),
        ]);

        $rows = collect();
        $day = fn ($date, $fallback = null) => ($date ?? $fallback ?? now())->format('Y-m-d');
        $base = fn ($doc, $amount) => ExchangeRates::toBase((float) $amount, $doc->exchange_rate);
        $note = fn ($doc, $amount, $text) => $doc->currency_code ? $text.' ('.number_format((float) $amount, 2).' '.$doc->currency_code.')' : $text;

        foreach ($supplier->purchaseInvoices as $inv) {
            $rows->push($this->row($day($inv->invoice_date, $inv->created_at), 1, 'invoice', 'فاتورة مشتريات', (string) $inv->invoice_number, 0, $base($inv, $inv->total), $note($inv, $inv->total, $inv->supplier_invoice_number ? 'فاتورة المورد '.$inv->supplier_invoice_number : 'فاتورة مشتريات')));
        }
        foreach ($supplier->payments as $pmt) {
            $rows->push($this->row($day($pmt->payment_date, $pmt->created_at), 2, 'payment', 'سند صرف', (string) ($pmt->payment_number ?: "PAY-{$pmt->id}"), $base($pmt, $pmt->amount), 0, $note($pmt, $pmt->amount, $pmt->notes ?: 'سند صرف')));
        }
        PurchaseReturn::where('supplier_id', $supplier->id)->whereNotIn('status', ['draft', 'cancelled'])->where(fn ($q) => $q->whereNull('settlement')->orWhere('settlement', 'credit'))->get()
            ->each(fn ($r) => $rows->push($this->row($day($r->return_date, $r->created_at), 3, 'return', 'مرتجع مشتريات', (string) $r->return_number, $base($r, $r->total ?? ((float) $r->subtotal + (float) $r->tax_amount)), 0, $r->reason ?: 'مرتجع مشتريات')));

        // Manual and adjustment entries on his payables account.
        $payables = app(AccountMap::class)->find('payables', $supplier->company_id);
        if ($payables) {
            JournalEntryLine::withoutGlobalScopes()
                ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('e.company_id', $supplier->company_id)->where('e.status', 'posted')->whereIn('e.entry_type', ['manual', 'adjustment'])
                ->where('journal_entry_lines.party_type', 'supplier')->where('journal_entry_lines.party_id', $supplier->id)->where('journal_entry_lines.account_id', $payables)
                ->get(['e.entry_number', 'e.date', 'e.description', 'journal_entry_lines.debit', 'journal_entry_lines.credit'])
                ->each(fn ($l) => $rows->push($this->row($day(Carbon::parse($l->date)), (float) $l->credit > 0 ? 1 : 3, 'journal', 'قيد يومية', (string) $l->entry_number, (float) $l->debit, (float) $l->credit, $l->description ?: 'قيد يومية')));
        }

        $rows = $rows->sortBy(fn ($r) => $r['date'].'_'.$r['order_key'])->values();

        $opening = (float) ($supplier->opening_balance ?? 0); // owed at the start: a credit balance
        if ($from) {
            $before = $rows->filter(fn ($r) => $r['date'] < $from->toDateString());
            $opening += $before->sum('credit') - $before->sum('debit');
            $rows = $rows->reject(fn ($r) => $r['date'] < $from->toDateString())->values();
        }
        if ($to) {
            $rows = $rows->reject(fn ($r) => $r['date'] > $to->toDateString())->values();
        }

        $balance = $opening;
        $transactions = collect();
        if ($opening != 0 || $rows->isEmpty()) {
            $transactions->push($this->row($from?->toDateString() ?? ($rows->first()['date'] ?? $day($supplier->created_at)), 0, 'opening_balance', 'رصيد افتتاحي', 'افتتاحي', max(-$opening, 0), max($opening, 0), 'الرصيد الافتتاحي') + ['balance' => round($opening, 2)]);
        }
        foreach ($rows as $row) {
            $balance += $row['credit'] - $row['debit'];
            $transactions->push($row + ['balance' => round($balance, 2)]);
        }

        return ['transactions' => $transactions, 'opening' => round($opening, 2), 'closing' => round($balance, 2)];
    }

    protected function row(string $date, int $order, string $type, string $label, string $reference, float $debit, float $credit, string $notes): array
    {
        return ['date' => $date, 'order_key' => $order, 'type' => $type, 'type_ar' => $label, 'reference' => $reference, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'notes' => $notes];
    }
}

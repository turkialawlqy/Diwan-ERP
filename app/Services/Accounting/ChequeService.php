<?php

namespace App\Services\Accounting;

use App\Models\Cheque;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cheque life cycle with postings.
 *
 * Incoming (from customers):  received  Dr cheques under collection / Cr receivables
 *                             cleared   Dr bank / Cr cheques under collection
 *                             bounced   Dr receivables / Cr cheques under collection
 * Outgoing (to suppliers):    issued    Dr payables / Cr cheques payable
 *                             cleared   Dr cheques payable / Cr bank
 *                             cancelled Dr cheques payable / Cr payables
 */
class ChequeService
{
    public const TRANSITIONS = [
        'incoming' => ['pending' => ['deposited', 'cleared', 'bounced', 'cancelled'], 'deposited' => ['cleared', 'bounced']],
        'outgoing' => ['pending' => ['cleared', 'cancelled']],
    ];

    public function __construct(
        protected PostingService $posting,
        protected DocumentPoster $documents,
    ) {}

    public function register(array $data): Cheque
    {
        return DB::transaction(function () use ($data) {
            $cheque = Cheque::create(array_merge($data, [
                'status' => 'pending',
                'history' => [['status' => 'pending', 'date' => now()->toDateString(), 'by' => auth()->id()]],
                'created_by' => auth()->id(),
            ]));

            $party = $this->partyLine($cheque);
            $incoming = $cheque->direction === 'incoming';

            $this->posting->post([
                'date' => $cheque->issue_date,
                'reference' => $cheque->cheque_number,
                'description' => ($incoming ? 'استلام شيك رقم ' : 'إصدار شيك رقم ').$cheque->cheque_number.' - '.$cheque->party_name,
                'source' => $cheque,
                'lines' => $incoming
                    ? [['role' => 'cheques_under_collection', 'debit' => (float) $cheque->amount], array_merge($party, ['credit' => (float) $cheque->amount])]
                    : [array_merge($party, ['debit' => (float) $cheque->amount]), ['role' => 'cheques_payable', 'credit' => (float) $cheque->amount]],
            ]);

            // The party owes (or is owed) less once the cheque is handed over.
            $this->syncPartyBalance($cheque, -1);
            $this->settleLinkedInvoice($cheque, 1);

            return $cheque;
        });
    }

    /**
     * Records a cheque whose entry the receipt / payment voucher already
     * posted (to cheques under collection / payable), so it can be cleared,
     * bounced or cancelled from the cheques register.
     */
    public function track(array $data): Cheque
    {
        return Cheque::create(array_merge($data, [
            'status' => 'pending',
            'history' => [['status' => 'pending', 'date' => now()->toDateString(), 'by' => auth()->id()]],
            'created_by' => auth()->id(),
        ]));
    }

    public function transition(Cheque $cheque, string $status, Carbon $date, ?int $bankAccountId = null): Cheque
    {
        $allowed = self::TRANSITIONS[$cheque->direction][$cheque->status] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw new AccountingException('لا يمكن تغيير حالة الشيك من «'.$cheque->status.'» إلى «'.$status.'».');
        }

        // A cheque received / issued with a voucher: bouncing or cancelling it undoes the voucher
        // (its entry, invoice allocations and party balance), so nothing is posted twice.
        if (in_array($status, ['bounced', 'cancelled'], true) && $cheque->source_type && $cheque->source_id) {
            $voucher = Relation::getMorphedModel($cheque->source_type) ?? $cheque->source_type;
            $document = $voucher::find($cheque->source_id);
            if ($document) {
                $method = $document instanceof CustomerPayment ? 'customerPayment' : 'supplierPayment';
                app(DocumentCancellation::class)->{$method}($document, ($status === 'bounced' ? 'ارتداد الشيك ' : 'إلغاء الشيك ').$cheque->cheque_number, $status === 'bounced' ? 'bounced' : null);

                return $cheque->fresh();
            }
        }

        return DB::transaction(function () use ($cheque, $status, $date, $bankAccountId) {
            $amount = (float) $cheque->amount;
            $bank = $bankAccountId ?? $cheque->bank_account_id;
            $incoming = $cheque->direction === 'incoming';
            $lines = null;
            $description = null;

            if ($status === 'cleared') {
                $bankLine = ['account_id' => $this->documents->cashAccount($bank, 'bank_transfer')];
                $lines = $incoming
                    ? [array_merge($bankLine, ['debit' => $amount]), ['role' => 'cheques_under_collection', 'credit' => $amount]]
                    : [['role' => 'cheques_payable', 'debit' => $amount], array_merge($bankLine, ['credit' => $amount])];
                $description = 'تحصيل/صرف الشيك رقم '.$cheque->cheque_number;
            } elseif ($status === 'bounced' || $status === 'cancelled') {
                $party = $this->partyLine($cheque);
                $lines = $incoming
                    ? [array_merge($party, ['debit' => $amount]), ['role' => 'cheques_under_collection', 'credit' => $amount]]
                    : [['role' => 'cheques_payable', 'debit' => $amount], array_merge($party, ['credit' => $amount])];
                $description = ($status === 'bounced' ? 'ارتجاع الشيك رقم ' : 'إلغاء الشيك رقم ').$cheque->cheque_number;
                $this->syncPartyBalance($cheque, 1);
                $this->settleLinkedInvoice($cheque, -1);
            }

            if ($lines) {
                $this->posting->post([
                    'date' => $date,
                    'reference' => $cheque->cheque_number,
                    'description' => $description,
                    'source' => $cheque,
                    'lines' => $lines,
                ]);
            }

            $history = $cheque->history ?? [];
            $history[] = ['status' => $status, 'date' => $date->toDateString(), 'by' => auth()->id()];

            $cheque->update(['status' => $status, 'history' => $history, 'bank_account_id' => $bank]);

            return $cheque;
        });
    }

    /**
     * A cheque registered against an invoice pays it ($sign 1) or, when it
     * bounces / is cancelled, reopens it ($sign -1).
     */
    protected function settleLinkedInvoice(Cheque $cheque, int $sign): void
    {
        [$model, $id, $partyKey] = $cheque->direction === 'incoming'
            ? [Invoice::class, $cheque->invoice_id, 'customer_id']
            : [PurchaseInvoice::class, $cheque->purchase_invoice_id, 'supplier_id'];
        if (! $id) {
            return;
        }

        $invoice = $model::whereKey($id)->lockForUpdate()->firstOrFail();
        $amount = (float) $cheque->amount;
        if ($sign > 0) {
            if ((int) $invoice->{$partyKey} !== (int) $cheque->party_id || ! app(ExchangeRates::class)->isBase($invoice->currency_code)) {
                throw new AccountingException('الفاتورة المحددة لا تخص صاحب الشيك أو ليست بالعملة الأساسية.');
            }
            if ($amount > (float) $invoice->remaining_amount + 0.01) {
                throw new AccountingException("مبلغ الشيك أكبر من المتبقي على الفاتورة ({$invoice->remaining_amount}).");
            }
        }

        $paid = max(0, round((float) $invoice->paid_amount + $sign * $amount, 2));
        $remaining = max(0, round((float) $invoice->remaining_amount - $sign * $amount, 2));
        $invoice->update(['paid_amount' => $paid, 'remaining_amount' => $remaining,
            'payment_status' => $remaining <= 0.004 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid')]);
    }

    protected function partyLine(Cheque $cheque): array
    {
        return match ($cheque->party_type) {
            'customer' => ['role' => 'receivables', 'party_type' => 'customer', 'party_id' => $cheque->party_id],
            'supplier' => ['role' => 'payables', 'party_type' => 'supplier', 'party_id' => $cheque->party_id],
            default => ['role' => $cheque->direction === 'incoming' ? 'receivables' : 'payables'],
        };
    }

    /**
     * Keep the customer / supplier running balance in step with the cheque.
     */
    protected function syncPartyBalance(Cheque $cheque, int $sign): void
    {
        $model = match ($cheque->party_type) {
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            default => null,
        };

        if ($model && $cheque->party_id) {
            $model::whereKey($cheque->party_id)->increment('current_balance', $sign * (float) $cheque->amount);
        }
    }
}

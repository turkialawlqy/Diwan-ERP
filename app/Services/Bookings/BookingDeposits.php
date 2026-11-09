<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\Documents\DocumentNumberService;
use App\Services\Sales\SalesCycleService;
use Illuminate\Validation\ValidationException;

/**
 * The money taken when a booking is made. It is a receipt like any other (cash/bank in, the
 * customer in credit), so the books show it the day it is received. Done → it settles the
 * invoice; cancelled / no-show → it stays as the customer's credit or is forfeited as income.
 * BookingService decides which: a cancellation before the free-cancellation window never forfeits.
 */
class BookingDeposits
{
    public const METHODS = ['cash', 'card', 'bank_transfer'];

    public function __construct(protected DocumentNumberService $numbers, protected DocumentPoster $poster, protected PostingService $posting) {}

    public function receive(Booking $booking, float $amount, string $method = 'cash', ?int $bankAccountId = null): CustomerPayment
    {
        if (! in_array($method, self::METHODS, true)) {
            throw ValidationException::withMessages(['deposit_method' => 'طريقة استلام العربون غير معروفة.']);
        }
        $date = now()->toDateString();
        $payment = CustomerPayment::create([
            'customer_id' => $booking->customer_id,
            'branch_id' => $booking->branch_id,
            'payment_number' => $this->numbers->next('customer_payment', now()),
            'payment_date' => $date,
            'amount' => round($amount, 2),
            'payment_method' => $method,
            'bank_account_id' => $bankAccountId,
            'reference_number' => $booking->number,
            'notes' => "عربون الحجز {$booking->number}",
            'exchange_rate' => 1,
        ]);
        Customer::whereKey($booking->customer_id)->decrement('current_balance', round($amount, 2));
        $this->poster->customerPayment($payment);
        $booking->forceFill(['deposit_amount' => round($amount, 2), 'deposit_payment_id' => $payment->id, 'deposit_status' => 'held'])->save();

        return $payment;
    }

    /** The held deposit settles the booking's invoice (what it does not cover stays the customer's credit). */
    public function apply(Booking $booking, Invoice $invoice): void
    {
        $payment = $this->held($booking);
        if (! $payment) {
            return;
        }
        app(SalesCycleService::class)->allocatePayment($payment, [['invoice_id' => $invoice->id, 'amount' => min((float) $payment->amount, (float) $invoice->remaining_amount)]]);
        $booking->forceFill(['deposit_status' => 'applied'])->save();
    }

    /** Cancelled or no-show: keep it as the customer's credit (refund or use later), or take it as income. */
    public function release(Booking $booking, bool $forfeit): void
    {
        $payment = $this->held($booking);
        if (! $payment) {
            return;
        }
        if (! $forfeit) {
            $booking->forceFill(['deposit_status' => 'credited'])->save();

            return;
        }
        $amount = (float) $payment->amount;
        $this->posting->post([
            'date' => now()->toDateString(),
            'reference' => $booking->number,
            'description' => "مصادرة عربون الحجز {$booking->number}",
            'source' => $booking,
            'lines' => [
                ['role' => 'receivables', 'debit' => $amount, 'description' => "عربون مصادَر - {$booking->number}", 'party_type' => 'customer', 'party_id' => $booking->customer_id],
                ['role' => 'other_revenue', 'credit' => $amount, 'description' => "عربون مصادَر - {$booking->number}"],
            ],
        ]);
        Customer::whereKey($booking->customer_id)->increment('current_balance', $amount);
        $booking->forceFill(['deposit_status' => 'forfeited'])->save();
    }

    /** Nothing to settle (package or free visit): the deposit stays the customer's credit. */
    public function credit(Booking $booking): void
    {
        if ($this->held($booking)) {
            $booking->forceFill(['deposit_status' => 'credited'])->save();
        }
    }

    protected function held(Booking $booking): ?CustomerPayment
    {
        return $booking->deposit_status === 'held' && $booking->deposit_payment_id ? CustomerPayment::find($booking->deposit_payment_id) : null;
    }
}

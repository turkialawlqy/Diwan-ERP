<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CustomerPaymentController extends Controller
{
    /**
     * Display a listing of customer payments.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = CustomerPayment::with(['customer', 'invoice']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $payments = $query->orderBy('payment_date', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $stats = [
            'total_payments' => CustomerPayment::count(),
            'total_amount' => (float) CustomerPayment::sum('amount'),
        ];

        return Inertia::render('Sales/Payments/Index', [
            'payments' => $payments,
            'filters' => [
                'search' => $search,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Store a payment against an invoice or customer account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'invoice_id' => 'nullable|tenant_exists:invoices,id',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,cheque',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated) {
            $paymentCount = CustomerPayment::count() + 1;
            $paymentNumber = sprintf('REC-%s-%05d', date('y'), $paymentCount);

            $payment = CustomerPayment::create(array_merge($validated, [
                'company_id' => auth()->user()->company_id,
                'payment_number' => $paymentNumber,
            ]));

            // If attached to invoice, update invoice paid amount
            if (! empty($validated['invoice_id'])) {
                // Locked to prevent two concurrent payments from both reading the
                // same stale remaining_amount and jointly overpaying the invoice.
                $invoice = Invoice::where('id', $validated['invoice_id'])->lockForUpdate()->first();

                if ($validated['amount'] > $invoice->remaining_amount + 0.01) {
                    throw ValidationException::withMessages([
                        'amount' => "مبلغ الدفعة ({$validated['amount']}) أكبر من المبلغ المتبقي على الفاتورة ({$invoice->remaining_amount}).",
                    ]);
                }

                $newPaid = $invoice->paid_amount + $validated['amount'];
                $newRemaining = max(0, $invoice->total - $newPaid);
                $status = $newRemaining <= 0 ? 'paid' : 'partially_paid';

                $invoice->update([
                    'paid_amount' => $newPaid,
                    'remaining_amount' => $newRemaining,
                    'payment_status' => $status,
                ]);
            }

            // Deduct customer current balance
            Customer::where('id', $validated['customer_id'])->decrement('current_balance', $validated['amount']);
        });

        return back()->with('success', 'تم تسجيل سند القبض وتحديث الرصيد بنجاح');
    }
}

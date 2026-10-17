<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Accounting\ChequeService;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\ExchangeRates;
use App\Services\Saas\WebhookDispatcher;
use App\Services\Sales\SalesCycleService;
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
            'total_payments' => CustomerPayment::where('status', '!=', 'cancelled')->count(),
            'total_amount' => (float) CustomerPayment::where('status', '!=', 'cancelled')->sum('amount'),
        ];

        return Inertia::render('Sales/Payments/Index', [
            'payments' => $payments,
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('type')->get(['id', 'name', 'type']),
            'filters' => [
                'search' => $search,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Store a payment against an invoice or customer account.
     */
    /** Open invoices of a customer for the receipt voucher form. */
    public function openInvoices(Request $request)
    {
        $request->validate(['customer_id' => 'required|tenant_exists:customers,id']);

        return response()->json(['data' => Invoice::where('customer_id', $request->integer('customer_id'))->where('remaining_amount', '>', 0)
            ->orderBy('invoice_date')->limit(200)
            ->get(['id', 'invoice_number', 'invoice_date', 'total', 'remaining_amount', 'currency_code'])]);
    }

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
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'auto_allocate' => 'nullable|boolean',
            'allocations' => 'nullable|array|max:200',
            'allocations.*.invoice_id' => 'required|tenant_exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0',
        ] + ExchangeRates::rules());

        // A payment against one invoice defaults to that invoice's currency.
        if (empty($validated['currency_code']) && ! empty($validated['invoice_id'])) {
            $validated['currency_code'] = Invoice::whereKey($validated['invoice_id'])->value('currency_code');
        }
        [$validated['currency_code'], $validated['exchange_rate']] = app(ExchangeRates::class)
            ->resolve($validated['currency_code'] ?? null, $validated['exchange_rate'] ?? null, $validated['payment_date']);

        DB::transaction(function () use ($validated) {
            $payment = CustomerPayment::create(array_merge(
                collect($validated)->except(['allocations', 'auto_allocate'])->all(),
                ['payment_number' => $this->nextNumber('customer_payment', $validated['payment_date'])],
            ));

            // Settle invoices: the chosen invoice, explicit allocations, or
            // automatically the oldest open invoices first.
            $allocations = $validated['allocations'] ?? null;
            if (! empty($validated['invoice_id'])) {
                $invoice = Invoice::whereKey($validated['invoice_id'])->lockForUpdate()->first();
                if ((int) $invoice->customer_id !== (int) $validated['customer_id']) {
                    throw ValidationException::withMessages(['invoice_id' => 'الفاتورة المحددة لا تخص هذا العميل.']);
                }
                if (($invoice->currency_code ?: null) !== $validated['currency_code']) {
                    throw ValidationException::withMessages(['currency_code' => 'عملة السند يجب أن تطابق عملة الفاتورة ('.($invoice->currency_code ?: app(ExchangeRates::class)->base()).').']);
                }
                if ((float) $validated['amount'] > (float) $invoice->remaining_amount + 0.01) {
                    throw ValidationException::withMessages([
                        'amount' => "مبلغ الدفعة ({$validated['amount']}) أكبر من المبلغ المتبقي على الفاتورة ({$invoice->remaining_amount}).",
                    ]);
                }
                $allocations = [['invoice_id' => $invoice->id, 'amount' => (float) $validated['amount']]];
            } elseif ($allocations !== null && array_sum(array_column($allocations, 'amount')) > (float) $validated['amount'] + 0.005) {
                throw ValidationException::withMessages(['allocations' => 'مجموع المبالغ الموزعة أكبر من مبلغ السند.']);
            }

            if ($allocations !== null || ($validated['auto_allocate'] ?? true)) {
                app(SalesCycleService::class)->allocatePayment($payment, $allocations);
            }

            Customer::where('id', $validated['customer_id'])->decrement('current_balance', ExchangeRates::toBase((float) $validated['amount'], $payment->exchange_rate));

            app(DocumentPoster::class)->customerPayment($payment);
            if ($payment->payment_method === 'cheque') {
                app(ChequeService::class)->track([
                    'direction' => 'incoming', 'cheque_number' => $payment->reference_number ?: $payment->payment_number, 'bank_account_id' => $payment->bank_account_id,
                    'party_type' => 'customer', 'party_id' => $payment->customer_id, 'party_name' => $payment->customer?->name ?? '',
                    'amount' => round((float) $payment->amount * (float) ($payment->exchange_rate ?: 1), 2),
                    'issue_date' => $payment->payment_date, 'due_date' => $payment->payment_date, 'notes' => 'سند قبض '.$payment->payment_number,
                    'source_type' => $payment->getMorphClass(), 'source_id' => $payment->id,
                ]);
            }

            app(WebhookDispatcher::class)->dispatch('payment.received', [
                'id' => $payment->id, 'number' => $payment->payment_number, 'customer_id' => $payment->customer_id,
                'amount' => (float) $payment->amount, 'date' => $payment->payment_date, 'method' => $payment->payment_method,
            ]);
        });

        return back()->with('success', 'تم تسجيل سند القبض وتحديث الرصيد بنجاح');
    }

    /**
     * Allocate the unallocated part of a receipt to open invoices.
     */
    public function allocate(Request $request, CustomerPayment $payment)
    {
        $validated = $request->validate([
            'allocations' => 'nullable|array|max:200',
            'allocations.*.invoice_id' => 'required|tenant_exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0',
        ]);

        abort_if($payment->status === 'cancelled', 422, 'السند ملغى.');
        DB::transaction(fn () => app(SalesCycleService::class)->allocatePayment(
            CustomerPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail(),
            $validated['allocations'] ?? null,
        ));

        return back()->with('success', 'تم توزيع السند على الفواتير');
    }
}

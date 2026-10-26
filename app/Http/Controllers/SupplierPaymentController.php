<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Accounting\ChequeService;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\ExchangeRates;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SupplierPaymentController extends Controller
{
    /**
     * Display a listing of supplier payments (سندات صرف).
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = SupplierPayment::with(['supplier', 'purchaseInvoice']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%");
                    });
            });
        }

        $payments = $query->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $suppliers = Supplier::where('status', 'active')->orderBy('name')->limit(500)->get(['id', 'name', 'current_balance']);
        $unpaidInvoices = PurchaseInvoice::where('remaining_amount', '>', 0)
            ->with('supplier:id,name')
            ->get(['id', 'supplier_id', 'invoice_number', 'total', 'remaining_amount', 'currency_code']);

        $stats = [
            'total_payments_count' => SupplierPayment::where('status', '!=', 'cancelled')->count(),
            'total_paid_amount' => SupplierPayment::where('status', '!=', 'cancelled')->sum('amount'),
        ];

        return Inertia::render('Purchases/Payments/Index', [
            'payments' => $payments,
            'suppliers' => $suppliers,
            'unpaidInvoices' => $unpaidInvoices,
            'stats' => $stats,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    /**
     * Store a newly created supplier payment voucher (سند صرف).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|tenant_exists:suppliers,id',
            'purchase_invoice_id' => 'nullable|tenant_exists:purchase_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,cheque',
            'reference_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
        ] + ExchangeRates::rules());

        if (empty($validated['currency_code']) && ! empty($validated['purchase_invoice_id'])) {
            $validated['currency_code'] = PurchaseInvoice::whereKey($validated['purchase_invoice_id'])->value('currency_code');
        }
        [$validated['currency_code'], $validated['exchange_rate']] = app(ExchangeRates::class)
            ->resolve($validated['currency_code'] ?? null, $validated['exchange_rate'] ?? null, $validated['payment_date']);

        $companyId = app(TenantManager::class)->getTenantId()
            ?? auth()->user()?->company_id;

        if (! $companyId) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        DB::transaction(function () use ($validated, $companyId) {
            $paymentNumber = $this->nextNumber('supplier_payment', $validated['payment_date']);

            $payment = SupplierPayment::create([
                'company_id' => $companyId,
                'supplier_id' => $validated['supplier_id'],
                'purchase_invoice_id' => $validated['purchase_invoice_id'] ?? null,
                'payment_number' => $paymentNumber,
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'currency_code' => $validated['currency_code'],
                'exchange_rate' => $validated['exchange_rate'],
            ]);

            // Deduct supplier balance
            $supplier = Supplier::find($validated['supplier_id']);
            if ($supplier) {
                $supplier->decrement('current_balance', ExchangeRates::toBase((float) $validated['amount'], $validated['exchange_rate']));
            }

            // Update linked purchase invoice if applicable
            if (! empty($validated['purchase_invoice_id'])) {
                // Locked to prevent two concurrent payments from both reading the
                // same stale remaining_amount and jointly overpaying the invoice.
                $invoice = PurchaseInvoice::where('id', $validated['purchase_invoice_id'])->lockForUpdate()->first();
                if ($invoice && (int) $invoice->supplier_id !== (int) $validated['supplier_id']) {
                    throw ValidationException::withMessages(['purchase_invoice_id' => 'الفاتورة المحددة لا تخص هذا المورد.']);
                }
                if ($invoice) {
                    if (($invoice->currency_code ?: null) !== $validated['currency_code']) {
                        throw ValidationException::withMessages(['currency_code' => 'عملة السند يجب أن تطابق عملة فاتورة الشراء ('.($invoice->currency_code ?: app(ExchangeRates::class)->base()).').']);
                    }
                    if ($validated['amount'] > $invoice->remaining_amount + 0.01) {
                        throw ValidationException::withMessages([
                            'amount' => "مبلغ الدفعة ({$validated['amount']}) أكبر من المبلغ المتبقي على فاتورة الشراء ({$invoice->remaining_amount}).",
                        ]);
                    }

                    $newPaid = $invoice->paid_amount + $validated['amount'];
                    // Remaining already reflects purchase returns: never recompute it from the total.
                    $newRemaining = max(0, round((float) $invoice->remaining_amount - (float) $validated['amount'], 2));
                    $newStatus = $newRemaining <= 0 ? 'paid' : 'partially_paid';

                    $invoice->update([
                        'paid_amount' => $newPaid,
                        'remaining_amount' => $newRemaining,
                        'payment_status' => $newStatus,
                    ]);
                }
            }

            app(DocumentPoster::class)->supplierPayment($payment);
            if ($payment->payment_method === 'cheque') {
                app(ChequeService::class)->track([
                    'direction' => 'outgoing', 'cheque_number' => $payment->reference_number ?: $payment->payment_number, 'bank_account_id' => $payment->bank_account_id,
                    'party_type' => 'supplier', 'party_id' => $payment->supplier_id, 'party_name' => $payment->supplier?->name ?? '',
                    'amount' => round((float) $payment->amount * (float) ($payment->exchange_rate ?: 1), 2),
                    'issue_date' => $payment->payment_date, 'due_date' => $payment->payment_date, 'notes' => 'سند صرف '.$payment->payment_number,
                    'source_type' => $payment->getMorphClass(), 'source_id' => $payment->id,
                ]);
            }

            // Paid at the payment rate, booked at the bill rate: for a supplier
            // a higher payment rate is a loss.
            if (! empty($invoice) && $validated['currency_code']) {
                $difference = ExchangeRates::toBase((float) $validated['amount'], $invoice->exchange_rate) - ExchangeRates::toBase((float) $validated['amount'], $validated['exchange_rate']);
                if (round($difference, 2) != 0.0) {
                    app(DocumentPoster::class)->exchangeDifference($payment, 'supplier', (int) $payment->supplier_id, $difference, $payment->payment_date, $payment->payment_number);
                    Supplier::whereKey($payment->supplier_id)->decrement('current_balance', round($difference, 2));
                }
            }
        });

        return back()->with('success', 'تم تسجيل سند الصرف وتحديث رصيد المورد بنجاح');
    }
}

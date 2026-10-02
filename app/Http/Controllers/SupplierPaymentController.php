<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Accounting\DocumentPoster;
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
            ->get(['id', 'supplier_id', 'invoice_number', 'total', 'remaining_amount']);

        $stats = [
            'total_payments_count' => SupplierPayment::count(),
            'total_paid_amount' => SupplierPayment::sum('amount'),
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
        ]);

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
            ]);

            // Deduct supplier balance
            $supplier = Supplier::find($validated['supplier_id']);
            if ($supplier) {
                $supplier->decrement('current_balance', $validated['amount']);
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
                    if ($validated['amount'] > $invoice->remaining_amount + 0.01) {
                        throw ValidationException::withMessages([
                            'amount' => "مبلغ الدفعة ({$validated['amount']}) أكبر من المبلغ المتبقي على فاتورة الشراء ({$invoice->remaining_amount}).",
                        ]);
                    }

                    $newPaid = $invoice->paid_amount + $validated['amount'];
                    $newRemaining = max(0, $invoice->total - $newPaid);
                    $newStatus = $newRemaining <= 0 ? 'paid' : 'partially_paid';

                    $invoice->update([
                        'paid_amount' => $newPaid,
                        'remaining_amount' => $newRemaining,
                        'payment_status' => $newStatus,
                    ]);
                }
            }

            app(DocumentPoster::class)->supplierPayment($payment);
        });

        return back()->with('success', 'تم تسجيل سند الصرف وتحديث رصيد المورد بنجاح');
    }
}

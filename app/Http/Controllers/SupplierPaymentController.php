<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->paginate($request->input('per_page', 20))
            ->withQueryString();

        $suppliers = Supplier::where('status', 'active')->get(['id', 'name', 'current_balance']);
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
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_invoice_id' => 'nullable|exists:purchase_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,cheque',
            'reference_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        $companyId = app(TenantManager::class)->getTenantId() 
            ?? auth()->user()?->company_id 
            ?? \App\Models\Company::first()?->id ?? 1;

        DB::transaction(function () use ($validated, $companyId) {
            $paymentCount = SupplierPayment::count() + 1;
            $paymentNumber = sprintf("SPAY-%s-%06d", date('y'), $paymentCount);

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
            ]);

            // Deduct supplier balance
            $supplier = Supplier::find($validated['supplier_id']);
            if ($supplier) {
                $supplier->decrement('current_balance', $validated['amount']);
            }

            // Update linked purchase invoice if applicable
            if (!empty($validated['purchase_invoice_id'])) {
                $invoice = PurchaseInvoice::find($validated['purchase_invoice_id']);
                if ($invoice) {
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
        });

        return back()->with('success', 'تم تسجيل سند الصرف وتحديث رصيد المورد بنجاح');
    }
}

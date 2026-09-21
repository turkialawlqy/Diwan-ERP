<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Invoice;
use App\Services\TenantManager;
use App\Services\ZatcaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class QuotationController extends Controller
{
    /**
     * Display a listing of quotations.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');

        $query = Quotation::with('customer');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $quotations = $query->orderBy('quotation_date', 'desc')
            ->paginate($request->input('per_page', 20))
            ->withQueryString();

        $stats = [
            'total' => Quotation::count(),
            'accepted' => Quotation::where('status', 'accepted')->count(),
            'pending' => Quotation::whereIn('status', ['draft', 'sent'])->count(),
            'total_value' => (float) Quotation::sum('total'),
        ];

        return Inertia::render('Sales/Quotations/Index', [
            'quotations' => $quotations,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Show quotation details.
     */
    public function show(Quotation $quotation)
    {
        $quotation->load(['customer', 'items']);
        $company = app(TenantManager::class)->getCompany();

        return Inertia::render('Sales/Quotations/Show', [
            'quotation' => $quotation,
            'company' => $company,
        ]);
    }

    /**
     * Convert quotation into a tax invoice.
     */
    public function convertToInvoice(Quotation $quotation)
    {
        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();

        $invoice = DB::transaction(function () use ($quotation, $company) {
            $year = date('y');
            $count = Invoice::count() + 1;
            $invoiceNumber = sprintf("INV-%s-1-%06d", $year, $count);

            $timestamp = Carbon::now()->format('Y-m-d\TH:i:s\Z');
            $zatcaQr = ZatcaService::generateQrCode(
                $company?->name ?? 'الأستاذ',
                $company?->tax_number ?? '300000000000003',
                $timestamp,
                $quotation->total,
                $quotation->tax_amount
            );

            $invoice = Invoice::create([
                'company_id' => $company->id,
                'customer_id' => $quotation->customer_id,
                'user_id' => auth()->id(),
                'invoice_number' => $invoiceNumber,
                'reference_number' => $quotation->quotation_number,
                'invoice_date' => Carbon::now()->toDateString(),
                'due_date' => Carbon::now()->addDays(30)->toDateString(),
                'subtotal' => $quotation->subtotal,
                'tax_rate' => 15.00,
                'tax_amount' => $quotation->tax_amount,
                'total' => $quotation->total,
                'paid_amount' => 0,
                'remaining_amount' => $quotation->total,
                'payment_status' => 'unpaid',
                'payment_method' => 'credit',
                'notes' => "تم التحويل من عرض سعر رقم {$quotation->quotation_number}",
                'zatca_uuid' => (string) Str::uuid(),
                'zatca_qr' => $zatcaQr,
                'zatca_status' => 'submitted',
            ]);

            foreach ($quotation->items as $qItem) {
                $invoice->items()->create([
                    'product_id' => $qItem->product_id,
                    'item_name' => $qItem->item_name,
                    'description' => $qItem->description,
                    'quantity' => $qItem->quantity,
                    'unit_price' => $qItem->unit_price,
                    'discount' => $qItem->discount,
                    'tax_rate' => $qItem->tax_rate,
                    'tax_amount' => $qItem->tax_amount,
                    'subtotal' => $qItem->subtotal,
                    'total' => $qItem->total,
                ]);
            }

            // Update customer balance
            Customer::where('id', $quotation->customer_id)->increment('current_balance', $quotation->total);

            // Update quotation status
            $quotation->update(['status' => 'converted']);

            return $invoice;
        });

        return redirect()->route('sales.invoices.show', $invoice->id)->with('success', 'تم تحويل عرض السعر إلى فاتورة مبيعات بنجاح');
    }
}

<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\DeliveryNote;
use App\Services\Sales\SalesCycleService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeliveryNoteController extends Controller
{
    public function __construct(protected SalesCycleService $cycle) {}

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:delivered,invoiced,cancelled']);

        $notes = DeliveryNote::with(['customer:id,name', 'salesOrder:id,order_number', 'warehouse:id,name_ar'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('delivery_number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('delivery_date')->orderByDesc('id')
            ->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Sales/Deliveries/Index', ['notes' => $notes, 'filters' => $filters]);
    }

    public function show(DeliveryNote $delivery)
    {
        $delivery->load(['customer', 'warehouse:id,name_ar', 'salesOrder:id,order_number', 'invoice:id,invoice_number', 'items']);

        return Inertia::render('Sales/Deliveries/Show', ['note' => $delivery]);
    }

    public function invoice(DeliveryNote $delivery)
    {
        $invoice = $this->cycle->invoiceDelivery($delivery);

        return redirect()->route('sales.invoices.show', $invoice->id)->with('success', "تم إصدار الفاتورة {$invoice->invoice_number} من إذن التسليم");
    }
}

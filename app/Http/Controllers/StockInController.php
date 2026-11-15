<?php

namespace App\Http\Controllers;

use App\Models\PurchaseDraft;
use App\Models\Warehouse;
use App\Services\Purchases\PurchaseDraftService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * «إدخال مواد للمستودع»: the warehouse keeper adds only items and quantities. The stock enters at once and a draft purchase
 * invoice opens for accounting to complete (supplier, date, prices).
 */
class StockInController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Inventory/StockIn/Index', [
            'drafts' => PurchaseDraft::with(['warehouse:id,name_ar', 'creator:id,name', 'receipt.items:id,goods_receipt_id'])
                ->latest('id')->paginate($this->perPage($request))->withQueryString()
                ->through(fn ($d) => [
                    'id' => $d->id, 'number' => $d->number, 'status' => $d->status, 'warehouse' => $d->warehouse?->name_ar, 'by' => $d->creator?->name,
                    'date' => $d->receipt?->receipt_date?->toDateString(), 'lines' => $d->receipt?->items->count() ?? 0, 'notes' => $d->notes,
                ]),
        ]);
    }

    public function create()
    {
        return Inertia::render('Inventory/StockIn/Create', [
            'warehouses' => Warehouse::where('is_active', true)->orderByDesc('is_main')->orderBy('name_ar')->get(['id', 'name_ar', 'is_main']),
            'suggestedNumber' => $this->peekNumber('purchase_draft'),
        ]);
    }

    public function store(Request $request, PurchaseDraftService $drafts)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            'receipt_date' => 'required|date|before_or_equal:today',
            'supplier_delivery_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.product_id' => 'required|tenant_exists:products,id',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.batch_number' => 'nullable|string|max:100',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.serials' => 'nullable|array|max:5000',
            'lines.*.serials.*' => 'string|max:100',
        ]);

        $draft = $drafts->createFromStockIn($data, $data['lines']);

        return redirect()->route('inventory.stock-in.index')->with('success', "دخلت المواد المستودع وفُتحت المسودة {$draft->number} بانتظار فاتورة المورد");
    }
}

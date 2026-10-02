<?php

namespace App\Http\Controllers;

use App\Models\ProductBatch;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Two-step transfers between warehouses: send (goods leave the source and sit
 * in "goods in transit") then receive (goods enter the destination; any
 * shortage is written off as an inventory adjustment).
 */
class StockTransferController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Inventory/Transfers/Index', [
            'transfers' => StockTransfer::with(['fromWarehouse:id,name_ar', 'toWarehouse:id,name_ar'])->withCount('items')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('id')->paginate($this->perPage($request)),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
            'filters' => $request->only('status'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'required|tenant_exists:warehouses,id|different:to_warehouse_id',
            'to_warehouse_id' => 'required|tenant_exists:warehouses,id',
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'required|tenant_exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.batch_id' => 'nullable|integer',
        ]);

        $transfer = DB::transaction(function () use ($validated) {
            $transfer = StockTransfer::create([
                'transfer_number' => $this->nextNumber('stock_transfer', $validated['transfer_date']),
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'transfer_date' => $validated['transfer_date'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'draft',
            ]);

            foreach ($validated['items'] as $item) {
                $transfer->items()->create([
                    'product_id' => $item['product_id'],
                    'batch_id' => $item['batch_id'] ?? null,
                    'quantity' => $item['quantity'],
                ]);
            }

            return $transfer;
        });

        return redirect()->route('inventory.transfers.show', $transfer->id)->with('success', 'تم إنشاء أمر التحويل');
    }

    public function show(StockTransfer $transfer): Response
    {
        return Inertia::render('Inventory/Transfers/Show', [
            'transfer' => $transfer->load(['items.product:id,name_ar,sku', 'fromWarehouse:id,name_ar', 'toWarehouse:id,name_ar']),
        ]);
    }

    public function send(StockTransfer $transfer, StockService $stock, DocumentPoster $poster)
    {
        if ($transfer->status !== 'draft') {
            throw ValidationException::withMessages(['transfer' => 'تم إرسال هذا التحويل مسبقاً.']);
        }

        DB::transaction(function () use ($transfer, $stock, $poster) {
            $total = 0.0;
            foreach ($transfer->items as $item) {
                $cost = $stock->issue([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'quantity' => (float) $item->quantity,
                    'batch_id' => $item->batch_id,
                    'type' => 'transfer_out',
                    'date' => $transfer->transfer_date,
                    'source' => $transfer,
                    'reference' => $transfer->transfer_number,
                ]);
                $item->update(['unit_cost' => (float) $item->quantity > 0 ? $cost / (float) $item->quantity : 0]);
                $total += $cost;
            }

            $poster->stockInTransit($transfer, $total, $transfer->transfer_date, "بضاعة في الطريق - تحويل {$transfer->transfer_number}");
            $transfer->update(['status' => 'in_transit', 'sent_at' => now(), 'sent_by' => auth()->id()]);
        });

        return back()->with('success', 'تم إرسال البضاعة وخصمها من المستودع المصدر');
    }

    public function receive(Request $request, StockTransfer $transfer, StockService $stock, DocumentPoster $poster)
    {
        if ($transfer->status !== 'in_transit') {
            throw ValidationException::withMessages(['transfer' => 'التحويل ليس في حالة "في الطريق".']);
        }

        $validated = $request->validate([
            'received' => 'nullable|array',
            'received.*' => 'numeric|min:0',
        ]);

        DB::transaction(function () use ($transfer, $validated, $stock, $poster) {
            $receivedValue = 0.0;
            $sentValue = 0.0;

            foreach ($transfer->items as $item) {
                $sent = (float) $item->quantity;
                $received = min($sent, (float) ($validated['received'][$item->id] ?? $sent));
                $sentValue += $sent * (float) $item->unit_cost;

                if ($received > 0) {
                    $batch = $item->batch_id ? ProductBatch::find($item->batch_id) : null;
                    $stock->receive([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $transfer->to_warehouse_id,
                        'quantity' => $received,
                        'unit_cost' => (float) $item->unit_cost,
                        'keep_average' => true,
                        'type' => 'transfer_in',
                        'date' => now(),
                        'source' => $transfer,
                        'reference' => $transfer->transfer_number,
                        'batch_number' => $batch?->batch_number,
                        'expiry_date' => $batch?->expiry_date?->toDateString(),
                    ]);
                    $receivedValue += $received * (float) $item->unit_cost;
                }

                $item->update(['received_quantity' => $received]);
            }

            $poster->stockInTransit($transfer, $receivedValue, now(), "استلام تحويل {$transfer->transfer_number}", true);

            // Goods lost in transit.
            $shortage = round($sentValue - $receivedValue, 2);
            if ($shortage > 0) {
                app(PostingService::class)->post([
                    'date' => now(),
                    'description' => "عجز تحويل {$transfer->transfer_number}",
                    'source' => $transfer,
                    'lines' => [['role' => 'inventory_adjustment', 'debit' => $shortage], ['role' => 'goods_in_transit', 'credit' => $shortage]],
                ]);
            }

            $transfer->update(['status' => 'received', 'received_at' => now(), 'received_by' => auth()->id()]);
        });

        return back()->with('success', 'تم استلام التحويل وإضافته للمستودع');
    }

    public function cancel(StockTransfer $transfer)
    {
        if ($transfer->status !== 'draft') {
            throw ValidationException::withMessages(['transfer' => 'لا يمكن إلغاء تحويل تم إرساله.']);
        }

        $transfer->update(['status' => 'cancelled']);

        return back()->with('success', 'تم إلغاء التحويل');
    }
}

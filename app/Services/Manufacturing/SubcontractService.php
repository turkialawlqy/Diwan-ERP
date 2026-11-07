<?php

namespace App\Services\Manufacturing;

use App\Models\Bom;
use App\Models\SubcontractOrder;
use App\Models\Supplier;
use App\Services\Accounting\PostingService;
use App\Services\Documents\DocumentNumberService;
use App\Services\Inventory\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manufacturing at a third party. Raw materials are moved to a warehouse that
 * stands for the subcontractor's site (they stay in our inventory), and when
 * the finished goods come back the materials used and the service charge make
 * up their cost: Dr inventory (total) / Cr inventory (materials) / Cr payables (service).
 */
class SubcontractService
{
    public function __construct(
        protected StockService $stock,
        protected PostingService $posting,
        protected DocumentNumberService $numbers,
    ) {}

    /** @param array{supplier_id:int, product_id:int, quantity:float, service_price:float, source_warehouse_id:int, vendor_warehouse_id:int, output_warehouse_id:int, order_date:string, expected_date?:?string, bom_id?:?int, notes?:?string} $data */
    public function create(array $data): SubcontractOrder
    {
        $quantity = round((float) $data['quantity'], 4);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'الكمية يجب أن تكون أكبر من صفر.']);
        }
        if ((int) $data['source_warehouse_id'] === (int) $data['vendor_warehouse_id']) {
            throw ValidationException::withMessages(['vendor_warehouse_id' => 'مستودع المقاول يجب أن يختلف عن مستودع الصرف.']);
        }

        return DB::transaction(function () use ($data, $quantity) {
            $bom = ! empty($data['bom_id']) ? Bom::with('items')->findOrFail($data['bom_id']) : Bom::with('items')->where('product_id', $data['product_id'])->where('is_active', true)->orderByDesc('is_default')->first();
            $order = SubcontractOrder::create([
                'order_number' => $this->numbers->next('subcontract_order', Carbon::parse($data['order_date'])),
                'supplier_id' => $data['supplier_id'], 'product_id' => $data['product_id'], 'bom_id' => $bom?->id,
                'source_warehouse_id' => $data['source_warehouse_id'], 'vendor_warehouse_id' => $data['vendor_warehouse_id'], 'output_warehouse_id' => $data['output_warehouse_id'],
                'quantity' => $quantity, 'service_price' => $data['service_price'], 'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null, 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            if ($bom) {
                $runs = $quantity / max(0.0001, (float) $bom->output_quantity);
                foreach ($bom->items as $item) {
                    $order->materials()->create(['product_id' => $item->component_id, 'planned_quantity' => round((float) $item->quantity * $runs * (1 + (float) $item->scrap_percent / 100), 4)]);
                }
            }

            return $order;
        });
    }

    /** Send the planned materials to the subcontractor. */
    public function send(SubcontractOrder $order, ?string $date = null): SubcontractOrder
    {
        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['order' => 'الإرسال لأمر في حالة مسودة.']);
        }

        return DB::transaction(function () use ($order, $date) {
            foreach ($order->materials()->get() as $m) {
                $left = round((float) $m->planned_quantity - (float) $m->sent_quantity, 4);
                if ($left > 0) {
                    $this->stock->transfer($m->product_id, $order->source_warehouse_id, $order->vendor_warehouse_id, $left, ['date' => $date ?? now(), 'source' => $order, 'reference' => $order->order_number]);
                    $m->update(['sent_quantity' => (float) $m->sent_quantity + $left]);
                }
            }
            $order->update(['status' => 'sent']);

            return $order;
        });
    }

    /** Take delivery of finished goods. */
    public function receive(SubcontractOrder $order, float $quantity, ?string $date = null, ?string $notes = null)
    {
        if (! in_array($order->status, ['sent', 'partially_received'], true)) {
            throw ValidationException::withMessages(['order' => 'لم تُرسل المواد بعد أو اكتمل الأمر.']);
        }
        $quantity = round($quantity, 4);
        $left = round((float) $order->quantity - (float) $order->received_quantity, 4);
        if ($quantity <= 0 || $quantity > $left + 0.00005) {
            throw ValidationException::withMessages(['quantity' => 'الكمية المستلمة أكبر من المتبقي في الأمر.']);
        }
        $date = Carbon::parse($date ?? now());

        return DB::transaction(function () use ($order, $quantity, $date, $notes, $left) {
            $share = $quantity / max(0.0001, (float) $order->quantity);
            $materialCost = 0.0;
            foreach ($order->materials()->get() as $m) {
                $use = round(min((float) $m->planned_quantity * $share, (float) $m->sent_quantity - (float) $m->consumed_quantity), 4);
                // The last delivery uses up whatever is left of the materials.
                if ($quantity >= $left - 0.00005) {
                    $use = round((float) $m->sent_quantity - (float) $m->consumed_quantity, 4);
                }
                if ($use > 0) {
                    $materialCost += $this->stock->issue(['product_id' => $m->product_id, 'warehouse_id' => $order->vendor_warehouse_id, 'quantity' => $use, 'type' => 'subcontract_out', 'date' => $date, 'source' => $order, 'reference' => $order->order_number]);
                    $m->update(['consumed_quantity' => (float) $m->consumed_quantity + $use]);
                }
            }
            $materialCost = round($materialCost, 2);
            $service = round($quantity * (float) $order->service_price, 2);
            $total = round($materialCost + $service, 2);

            $this->stock->receive(['product_id' => $order->product_id, 'warehouse_id' => $order->output_warehouse_id, 'quantity' => $quantity, 'unit_cost' => $total / $quantity, 'type' => 'subcontract_in', 'date' => $date, 'source' => $order, 'reference' => $order->order_number]);

            $lines = [['role' => 'inventory', 'debit' => $total]];
            if ($materialCost > 0) {
                $lines[] = ['role' => 'inventory', 'credit' => $materialCost];
            }
            if ($service > 0) {
                $lines[] = ['role' => 'payables', 'credit' => $service, 'party_type' => 'supplier', 'party_id' => $order->supplier_id];
            }
            if ($service > 0) {
                $this->posting->post(['date' => $date, 'description' => "استلام منتج مصنّع لدى الغير — {$order->order_number}", 'source' => $order, 'lines' => $lines]);
                // The contractor's fee is owed to him: his account (and credit-limit view) shows it.
                Supplier::whereKey($order->supplier_id)->increment('current_balance', $service);
            }

            $receipt = $order->receipts()->create(['date' => $date->toDateString(), 'quantity' => $quantity, 'material_cost' => $materialCost, 'service_cost' => $service, 'total_cost' => $total, 'notes' => $notes, 'created_by' => auth()->id()]);
            $received = (float) $order->received_quantity + $quantity;
            $order->update(['received_quantity' => $received, 'status' => $received >= (float) $order->quantity - 0.00005 ? 'completed' : 'partially_received']);

            return $receipt;
        });
    }

    /** Cancel an order nothing was received on; materials come back to the source warehouse. */
    public function cancel(SubcontractOrder $order): SubcontractOrder
    {
        if (! in_array($order->status, ['draft', 'sent'], true) || $order->receipts()->exists()) {
            throw ValidationException::withMessages(['order' => 'لا يمكن إلغاء أمر استُلمت منه بضاعة.']);
        }

        return DB::transaction(function () use ($order) {
            foreach ($order->materials()->get() as $m) {
                if ((float) $m->sent_quantity > 0) {
                    $this->stock->transfer($m->product_id, $order->vendor_warehouse_id, $order->source_warehouse_id, (float) $m->sent_quantity, ['source' => $order, 'reference' => $order->order_number]);
                    $m->update(['sent_quantity' => 0]);
                }
            }
            $order->update(['status' => 'cancelled']);

            return $order;
        });
    }
}

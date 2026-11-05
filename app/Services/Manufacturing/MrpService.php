<?php

namespace App\Services\Manufacturing;

use App\Models\Bom;
use App\Models\InventoryStock;
use App\Models\MrpRun;
use App\Models\MrpSuggestion;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseRequest;
use App\Models\SubcontractOrder;
use App\Models\Warehouse;
use App\Services\Purchases\PurchaseCycleService;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Material requirements planning. Demand (open sales orders, materials still
 * to be drawn by open production orders, reorder levels) is netted against
 * supply (stock, open purchase orders, open production and subcontract
 * orders). A shortage of a manufactured item becomes a production suggestion
 * and pushes its components' demand down the bill of materials; a shortage of
 * anything else becomes a purchase suggestion.
 */
class MrpService
{
    public function __construct(protected ProductionService $production, protected PurchaseCycleService $purchases) {}

    public function run(int $horizonDays = 30, ?string $notes = null): MrpRun
    {
        $today = Carbon::today();
        $horizon = $today->copy()->addDays($horizonDays);

        return DB::transaction(function () use ($horizonDays, $horizon, $today, $notes) {
            $run = MrpRun::create(['run_date' => $today->toDateString(), 'horizon_days' => $horizonDays, 'notes' => $notes, 'created_by' => auth()->id()]);

            $boms = Bom::with('items')->where('is_active', true)->orderBy('is_default')->get()->keyBy('product_id'); // default wins (last)
            $demand = [];
            $dates = [];
            $add = function (int $productId, float $qty, ?Carbon $date) use (&$demand, &$dates) {
                $demand[$productId] = ($demand[$productId] ?? 0) + $qty;
                if ($date && (! isset($dates[$productId]) || $date->lt($dates[$productId]))) {
                    $dates[$productId] = $date;
                }
            };

            // 1. open sales orders
            $items = DB::table('sales_order_items as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
                ->where('o.company_id', $this->companyId())->whereIn('o.status', ['confirmed', 'partially_delivered'])->whereNotNull('i.product_id')
                ->whereRaw('i.quantity > i.delivered_quantity')
                ->get(['i.product_id', 'i.quantity', 'i.delivered_quantity', 'o.delivery_date', 'o.order_date']);
            foreach ($items as $i) {
                $date = Carbon::parse($i->delivery_date ?? $i->order_date);
                if ($date->lte($horizon)) {
                    $add((int) $i->product_id, (float) $i->quantity - (float) $i->delivered_quantity, $date);
                }
            }
            // 2. materials still to be drawn by open production orders
            foreach (DB::table('production_order_materials as m')->join('production_orders as o', 'o.id', '=', 'm.production_order_id')
                ->where('o.company_id', $this->companyId())->whereIn('o.status', ['released', 'in_progress'])
                ->get(['m.product_id', 'm.planned_quantity', 'm.issued_quantity', 'm.returned_quantity', 'o.planned_start']) as $m) {
                $left = (float) $m->planned_quantity - ((float) $m->issued_quantity - (float) $m->returned_quantity);
                if ($left > 0) {
                    $add((int) $m->product_id, $left, $m->planned_start ? Carbon::parse($m->planned_start) : $today);
                }
            }

            // supply
            $onHand = InventoryStock::select('product_id', DB::raw('SUM(quantity) as q'))->groupBy('product_id')->pluck('q', 'product_id')->map(fn ($v) => (float) $v)->all();
            $supply = [];
            foreach (DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
                ->where('o.company_id', $this->companyId())->whereIn('o.status', ['approved', 'pending', 'partially_received', 'partially_invoiced'])->whereNotNull('i.product_id')
                ->get(['i.product_id', 'i.quantity', 'i.received_quantity']) as $i) {
                $supply[$i->product_id] = ($supply[$i->product_id] ?? 0) + max(0, (float) $i->quantity - (float) $i->received_quantity);
            }
            foreach (ProductionOrder::whereIn('status', ['draft', 'released', 'in_progress'])->get(['product_id', 'quantity', 'received_quantity']) as $o) {
                $supply[$o->product_id] = ($supply[$o->product_id] ?? 0) + max(0, (float) $o->quantity - (float) $o->received_quantity);
            }
            foreach (SubcontractOrder::whereIn('status', ['draft', 'sent', 'partially_received'])->get(['product_id', 'quantity', 'received_quantity']) as $o) {
                $supply[$o->product_id] = ($supply[$o->product_id] ?? 0) + max(0, (float) $o->quantity - (float) $o->received_quantity);
            }

            // top-down: an item's level is deeper than every item that uses it
            $level = [];
            $depth = function (int $pid, array $trail = []) use (&$depth, &$level, $boms) {
                if (isset($level[$pid])) {
                    return $level[$pid];
                }
                $level[$pid] = 0;
                if (in_array($pid, $trail, true) || ! $boms->has($pid)) {
                    return 0;
                }
                foreach ($boms[$pid]->items as $item) {
                    $level[$item->component_id] = max($level[$item->component_id] ?? 0, 1 + $depth($pid, [...$trail, $pid]));
                }

                return $level[$pid];
            };
            $products = Product::where('is_active', true)->get(['id', 'reorder_level', 'lead_time_days', 'track_inventory', 'type'])->keyBy('id');
            foreach ($products as $id => $_) {
                $depth($id);
            }
            // propagate levels (components of components)
            for ($pass = 0; $pass < 10; $pass++) {
                foreach ($boms as $pid => $bom) {
                    foreach ($bom->items as $item) {
                        $level[$item->component_id] = max($level[$item->component_id] ?? 0, ($level[$pid] ?? 0) + 1);
                    }
                }
            }

            $ids = collect(array_keys($demand))->merge($boms->flatMap(fn ($b) => $b->items->pluck('component_id')))->merge($products->filter(fn ($p) => $p->reorder_level !== null && (float) $p->reorder_level > 0)->keys())->merge($boms->keys())->unique()->all();
            usort($ids, fn ($a, $b) => ($level[$a] ?? 0) <=> ($level[$b] ?? 0));

            foreach ($ids as $pid) {
                $p = $products[$pid] ?? null;
                if (! $p || $p->type === 'service') {
                    continue;
                }
                $gross = $demand[$pid] ?? 0.0;
                $stock = $onHand[$pid] ?? 0.0;
                $incoming = $supply[$pid] ?? 0.0;
                $target = (float) ($p->reorder_level ?? 0);
                $net = round($gross + $target - $stock - $incoming, 4);
                if ($net <= 0.00005) {
                    continue;
                }
                $needBy = ($dates[$pid] ?? $today)->copy()->subDays((int) $p->lead_time_days);
                if ($needBy->lt($today)) {
                    $needBy = $today->copy();
                }
                $bom = $boms->get($pid);
                $type = $bom ? 'manufacture' : 'purchase';
                MrpSuggestion::create([
                    'mrp_run_id' => $run->id, 'product_id' => $pid, 'type' => $type, 'quantity' => $net, 'gross_demand' => $gross,
                    'on_hand' => $stock, 'supply' => $incoming, 'need_date' => $needBy->toDateString(),
                ]);
                if ($bom) {
                    $runs = $net / max(0.0001, (float) $bom->output_quantity);
                    foreach ($bom->items as $item) {
                        $add((int) $item->component_id, round((float) $item->quantity * $runs * (1 + (float) $item->scrap_percent / 100) * (1 + (float) $bom->scrap_percent / 100), 4), $needBy);
                    }
                }
            }

            return $run;
        });
    }

    /** Turn the chosen purchase suggestions into one purchase request. */
    public function convertToPurchaseRequest(array $suggestionIds): ?PurchaseRequest
    {
        $rows = MrpSuggestion::with('product:id,name_ar,purchase_price')->whereIn('id', $suggestionIds)->where('type', 'purchase')->where('status', 'new')->get();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'اختر اقتراحات شراء جديدة.']);
        }

        return DB::transaction(function () use ($rows) {
            $needBy = $rows->min('need_date');
            $request = $this->purchases->createRequest(
                ['request_date' => now()->toDateString(), 'required_date' => $needBy?->toDateString(), 'notes' => 'من تخطيط الاحتياجات (MRP)'],
                $rows->map(fn ($s) => ['product_id' => $s->product_id, 'item_name' => $s->product?->name_ar ?? '—', 'quantity' => $s->quantity, 'estimated_price' => $s->product?->purchase_price ?? 0])->all(),
            );
            MrpSuggestion::whereIn('id', $rows->pluck('id'))->update(['status' => 'converted', 'converted_type' => 'purchase_request', 'converted_id' => $request->id]);

            return $request;
        });
    }

    public function convertToProductionOrder(MrpSuggestion $suggestion, int $warehouseId): ProductionOrder
    {
        if ($suggestion->type !== 'manufacture' || $suggestion->status !== 'new') {
            throw ValidationException::withMessages(['id' => 'الاقتراح ليس أمر إنتاج جديداً.']);
        }

        return DB::transaction(function () use ($suggestion, $warehouseId) {
            $order = $this->production->create([
                'product_id' => $suggestion->product_id, 'quantity' => $suggestion->quantity, 'warehouse_id' => $warehouseId,
                'planned_start' => $suggestion->need_date?->toDateString(), 'notes' => 'من تخطيط الاحتياجات (MRP)',
            ]);
            $suggestion->update(['status' => 'converted', 'converted_type' => 'production_order', 'converted_id' => $order->id]);

            return $order;
        });
    }

    public function defaultWarehouseId(): ?int
    {
        return Warehouse::where('is_active', true)->orderByDesc('is_main')->value('id');
    }

    protected function companyId(): int
    {
        return (int) app(TenantManager::class)->getTenantId();
    }
}

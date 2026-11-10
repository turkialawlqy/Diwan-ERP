<?php

namespace App\Services\Manufacturing;

use App\Models\Bom;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionOrderOperation;
use App\Models\ProductionReceipt;
use App\Models\ProductionTimeLog;
use App\Models\QcInspection;
use App\Models\QcPlan;
use App\Models\Routing;
use App\Models\WorkCenter;
use App\Services\Accounting\PostingService;
use App\Services\Documents\DocumentNumberService;
use App\Services\Inventory\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Job costing. A production order draws materials from stock into work in
 * progress (Dr WIP / Cr inventory), takes labour and machine time at the work
 * centre rates (Dr WIP / Cr applied labour / overhead) and hands finished
 * goods back to stock at the cost accumulated (Dr inventory / Cr WIP). What is
 * left in WIP when the order is closed becomes a production variance.
 */
class ProductionService
{
    public function __construct(
        protected StockService $stock,
        protected PostingService $posting,
        protected DocumentNumberService $numbers,
    ) {}

    // ------------------------------------------------------------------ create

    /** @param array{product_id:int, quantity:float, warehouse_id:int, output_warehouse_id?:?int, bom_id?:?int, routing_id?:?int, planned_start?:?string, planned_end?:?string, sales_order_id?:?int, priority?:?int, notes?:?string} $data */
    public function create(array $data): ProductionOrder
    {
        $quantity = round((float) $data['quantity'], 4);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'الكمية يجب أن تكون أكبر من صفر.']);
        }

        return DB::transaction(function () use ($data, $quantity) {
            $bom = ! empty($data['bom_id'])
                ? Bom::with('items')->findOrFail($data['bom_id'])
                : $this->defaultBom((int) $data['product_id']);
            if ($bom && (int) $bom->product_id !== (int) $data['product_id']) {
                throw ValidationException::withMessages(['bom_id' => 'قائمة المواد لا تخص هذا المنتج.']);
            }
            $routing = ! empty($data['routing_id']) ? Routing::with('operations')->findOrFail($data['routing_id']) : ($bom?->routing_id ? Routing::with('operations')->find($bom->routing_id) : null);

            $start = ! empty($data['planned_start']) ? Carbon::parse($data['planned_start']) : now();
            $order = ProductionOrder::create([
                'order_number' => $this->numbers->next('production_order', $start),
                'product_id' => $data['product_id'],
                'bom_id' => $bom?->id,
                'routing_id' => $routing?->id,
                'warehouse_id' => $data['warehouse_id'],
                'output_warehouse_id' => $data['output_warehouse_id'] ?? null,
                'quantity' => $quantity,
                'planned_start' => $start->toDateString(),
                'planned_end' => ! empty($data['planned_end']) ? Carbon::parse($data['planned_end'])->toDateString() : null,
                'status' => 'draft',
                'priority' => $data['priority'] ?? 3,
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->planMaterials($order, $bom);
            $this->planOperations($order, $routing);

            return $order;
        });
    }

    public function defaultBom(int $productId): ?Bom
    {
        return Bom::with('items')->where('product_id', $productId)->where('is_active', true)
            ->orderByDesc('is_default')->orderByDesc('id')->first();
    }

    /** Materials needed for the quantity, with the BOM's and each line's scrap allowance. */
    public function planMaterials(ProductionOrder $order, ?Bom $bom): void
    {
        $order->materials()->delete();
        if (! $bom) {
            return;
        }
        $runs = (float) $order->quantity / max(0.0001, (float) $bom->output_quantity);
        foreach ($bom->items as $item) {
            $need = (float) $item->quantity * $runs * (1 + (float) $item->scrap_percent / 100) * (1 + (float) $bom->scrap_percent / 100);
            $order->materials()->create(['product_id' => $item->component_id, 'planned_quantity' => round($need, 4)]);
        }
    }

    public function planOperations(ProductionOrder $order, ?Routing $routing): void
    {
        $order->operations()->delete();
        if (! $routing) {
            return;
        }
        $start = Carbon::parse($order->planned_start ?? now());
        $elapsed = 0.0;
        foreach ($routing->operations->sortBy('seq') as $op) {
            $wc = WorkCenter::find($op->work_center_id);
            $efficiency = max(1.0, (float) ($wc?->efficiency ?? 100)) / 100;
            $minutes = round(((float) $op->setup_minutes + (float) $op->run_minutes * (float) $order->quantity) / $efficiency, 2);
            $dayMinutes = max(60.0, (float) ($wc?->dailyMinutes() ?? 480));
            $order->operations()->create([
                'seq' => $op->seq, 'name' => $op->name, 'work_center_id' => $op->work_center_id,
                'planned_minutes' => $minutes,
                'planned_date' => $start->copy()->addDays((int) floor($elapsed / $dayMinutes))->toDateString(),
            ]);
            $elapsed += $minutes;
        }
    }

    // ------------------------------------------------------------------ lifecycle

    public function release(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            $this->lock($order);
            $this->assertStatus($order, ['draft'], 'الإفراج يكون لأمر في حالة مسودة.');
            if (! $order->materials()->exists() && ! $order->operations()->exists()) {
                throw ValidationException::withMessages(['order' => 'أمر الإنتاج بلا مواد ولا عمليات. اختر قائمة مواد أو مسار تشغيل.']);
            }
            $order->update(['status' => 'released']);

            return $order;
        });
    }

    public function cancel(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            $this->lock($order);
            $this->assertStatus($order, ['draft', 'released'], 'لا يمكن إلغاء أمر بدأ تنفيذه.');
            if ($order->materials()->where('issued_quantity', '>', 0)->exists() || $order->timeLogs()->exists() || $order->receipts()->exists()) {
                throw ValidationException::withMessages(['order' => 'للأمر حركات مسجلة فلا يُلغى. أغلقه بدلاً من ذلك.']);
            }
            $order->update(['status' => 'cancelled']);

            return $order;
        });
    }

    // ------------------------------------------------------------------ materials

    public function issueMaterial(ProductionOrder $order, ProductionOrderMaterial $material, float $quantity, ?string $date = null): float
    {
        $quantity = round($quantity, 4);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'الكمية يجب أن تكون أكبر من صفر.']);
        }
        $date = Carbon::parse($date ?? now());

        return DB::transaction(function () use ($order, $material, $quantity, $date) {
            $this->lock($order);
            $this->assertStatus($order, ['released', 'in_progress'], 'صرف المواد لأمر مُفرَج عنه أو قيد التنفيذ فقط.');
            $this->lock($material);
            $cost = round($this->stock->issue([
                'product_id' => $material->product_id,
                'warehouse_id' => $order->warehouse_id,
                'quantity' => $quantity,
                'type' => 'production_out',
                'date' => $date,
                'source' => $order,
                'reference' => $order->order_number,
            ]), 2);

            $material->update(['issued_quantity' => (float) $material->issued_quantity + $quantity, 'cost' => (float) $material->cost + $cost]);
            $order->update(['material_cost' => (float) $order->material_cost + $cost]);
            $this->post($order, $date, "صرف مواد لأمر إنتاج {$order->order_number}", [['role' => 'wip', 'debit' => $cost], ['role' => 'inventory', 'credit' => $cost]]);
            $this->started($order, $date);

            return $cost;
        });
    }

    /** Issue whatever is still planned and not yet issued. */
    public function issueRemaining(ProductionOrder $order, ?string $date = null): float
    {
        return DB::transaction(function () use ($order, $date) {
            // Locked before reading what is left: a second click then finds nothing left to issue.
            $this->lock($order);
            $total = 0.0;
            foreach ($order->materials()->get() as $material) {
                $left = round((float) $material->planned_quantity - $material->netIssued(), 4);
                if ($left > 0) {
                    $total += $this->issueMaterial($order, $material, $left, $date);
                }
            }

            return $total;
        });
    }

    public function returnMaterial(ProductionOrder $order, ProductionOrderMaterial $material, float $quantity, ?string $date = null): float
    {
        $quantity = round($quantity, 4);
        $date = Carbon::parse($date ?? now());

        return DB::transaction(function () use ($order, $material, $quantity, $date) {
            $this->lock($order);
            $this->assertStatus($order, ['released', 'in_progress'], 'إرجاع المواد لأمر مُفرَج عنه أو قيد التنفيذ فقط.');
            $this->lock($material);
            if ($quantity <= 0 || $quantity > round($material->netIssued(), 4)) {
                throw ValidationException::withMessages(['quantity' => 'كمية الإرجاع أكبر من المصروف.']);
            }
            $unit = (float) $material->cost / max(0.0001, $material->netIssued());
            $cost = round($unit * $quantity, 2);
            $this->stock->receive([
                'product_id' => $material->product_id, 'warehouse_id' => $order->warehouse_id, 'quantity' => $quantity, 'unit_cost' => $unit,
                'type' => 'production_return', 'date' => $date, 'source' => $order, 'reference' => $order->order_number,
            ]);
            $material->update(['returned_quantity' => (float) $material->returned_quantity + $quantity, 'cost' => (float) $material->cost - $cost]);
            $order->update(['material_cost' => (float) $order->material_cost - $cost]);
            $this->post($order, $date, "إرجاع مواد من أمر إنتاج {$order->order_number}", [['role' => 'inventory', 'debit' => $cost], ['role' => 'wip', 'credit' => $cost]]);

            return $cost;
        });
    }

    // ------------------------------------------------------------------ labour and machine time

    /** @param array{work_center_id:int, minutes:float, operation_id?:?int, employee_id?:?int, quantity?:float, date?:?string, notes?:?string} $data */
    public function logTime(ProductionOrder $order, array $data): ProductionTimeLog
    {
        $minutes = round((float) $data['minutes'], 2);
        if ($minutes <= 0) {
            throw ValidationException::withMessages(['minutes' => 'الدقائق يجب أن تكون أكبر من صفر.']);
        }
        $center = WorkCenter::findOrFail($data['work_center_id']);
        $date = Carbon::parse($data['date'] ?? now());

        return DB::transaction(function () use ($order, $data, $minutes, $center, $date) {
            $this->lock($order);
            $this->assertStatus($order, ['released', 'in_progress'], 'تسجيل الوقت لأمر مُفرَج عنه أو قيد التنفيذ فقط.');
            $hours = $minutes / 60;
            $labour = round($hours * (float) $center->labour_rate_per_hour, 2);
            $overhead = round($hours * (float) $center->overhead_rate_per_hour, 2);

            $log = $order->timeLogs()->create([
                'operation_id' => $data['operation_id'] ?? null, 'work_center_id' => $center->id, 'employee_id' => $data['employee_id'] ?? null,
                'date' => $date->toDateString(), 'minutes' => $minutes, 'quantity' => $data['quantity'] ?? 0,
                'labour_cost' => $labour, 'overhead_cost' => $overhead, 'notes' => $data['notes'] ?? null, 'created_by' => auth()->id(),
            ]);

            $order->update(['labour_cost' => (float) $order->labour_cost + $labour, 'overhead_cost' => (float) $order->overhead_cost + $overhead]);

            if (! empty($data['operation_id']) && ($op = ProductionOrderOperation::where('production_order_id', $order->id)->whereKey($data['operation_id'])->lockForUpdate()->first())) {
                $done = (float) $op->completed_quantity + (float) ($data['quantity'] ?? 0);
                $op->update([
                    'actual_minutes' => (float) $op->actual_minutes + $minutes, 'completed_quantity' => $done,
                    'labour_cost' => (float) $op->labour_cost + $labour, 'overhead_cost' => (float) $op->overhead_cost + $overhead,
                    'status' => $done >= (float) $order->quantity ? 'done' : 'in_progress',
                ]);
            }

            $lines = [['role' => 'wip', 'debit' => $labour + $overhead]];
            if ($labour > 0) {
                $lines[] = ['role' => 'applied_labour', 'credit' => $labour];
            }
            if ($overhead > 0) {
                $lines[] = ['role' => 'applied_overhead', 'credit' => $overhead];
            }
            if ($labour + $overhead > 0) {
                $this->post($order, $date, "تكلفة تشغيل ({$center->name}) لأمر إنتاج {$order->order_number}", $lines, $center->cost_center_id);
            }
            $this->started($order, $date);

            return $log;
        });
    }

    // ------------------------------------------------------------------ finished goods

    /** @param array{quantity:float, scrap_quantity?:float, scrap_abnormal?:bool, date?:?string, batch_number?:?string, notes?:?string} $data */
    public function receive(ProductionOrder $order, array $data): ProductionReceipt
    {
        $good = round((float) $data['quantity'], 4);
        $scrap = round((float) ($data['scrap_quantity'] ?? 0), 4);
        if ($good <= 0 && $scrap <= 0) {
            throw ValidationException::withMessages(['quantity' => 'أدخل كمية سليمة أو هالكاً.']);
        }
        $date = Carbon::parse($data['date'] ?? now());

        return DB::transaction(function () use ($order, $data, $good, $scrap, $date) {
            $this->lock($order);
            $this->assertStatus($order, ['released', 'in_progress', 'completed'], 'استلام المنتج لأمر مُفرَج عنه أو قيد التنفيذ.');
            $receipt = $order->receipts()->create([
                'date' => $date->toDateString(), 'quantity' => $good, 'scrap_quantity' => $scrap,
                'scrap_abnormal' => (bool) ($data['scrap_abnormal'] ?? false), 'status' => 'posted',
                'batch_number' => $data['batch_number'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            $this->started($order, $date);

            // Product under a quality plan: stock and ledger wait for the inspection.
            $plan = QcPlan::where('is_active', true)->where('applies_to', 'production')->where(fn ($q) => $q->where('product_id', $order->product_id)->orWhereNull('product_id'))->orderByRaw('product_id is null')->first();
            if ($plan && $good > 0) {
                $receipt->update(['status' => 'pending_qc']);
                QcInspection::create([
                    'inspection_number' => $this->numbers->next('qc_inspection', $date), 'qc_plan_id' => $plan->id, 'product_id' => $order->product_id,
                    'source_type' => $receipt->getMorphClass(), 'source_id' => $receipt->id, 'inspection_date' => $date->toDateString(),
                    'inspected_quantity' => $good, 'accepted_quantity' => 0, 'rejected_quantity' => 0, 'result' => 'pending', 'disposition' => 'accept',
                    'inspector_id' => auth()->id(),
                ]);

                return $receipt->refresh();
            }

            return $this->postReceipt($receipt, $good, $scrap, (bool) $receipt->scrap_abnormal);
        });
    }

    /**
     * Value and book a receipt: the share of the order's WIP that the received
     * units represent goes to inventory; abnormal scrap is written off.
     */
    public function postReceipt(ProductionReceipt $receipt, float $good, float $scrap, bool $abnormal): ProductionReceipt
    {
        $order = $this->lock($receipt->order()->firstOrFail());
        $date = Carbon::parse($receipt->date);
        $wip = max(0.0, $order->wipBalance());
        $remaining = max($order->remainingQuantity(), $good + ($abnormal ? $scrap : 0));
        $share = $remaining > 0 ? 1 / $remaining : 0;

        $transfer = $good > 0 ? round($wip * $share * $good, 2) : 0.0;
        $scrapCost = $abnormal && $scrap > 0 ? round($wip * $share * $scrap, 2) : 0.0;
        // The last unit of the order takes whatever is left, so nothing stays behind through rounding.
        if ($order->remainingQuantity() > 0 && $good >= $order->remainingQuantity() - 0.00005 && ! $scrapCost) {
            $transfer = round($wip, 2);
        }

        $unit = $good > 0 ? round($transfer / $good, 4) : 0.0;
        if ($good > 0) {
            $this->stock->receive([
                'product_id' => $order->product_id, 'warehouse_id' => $order->output_warehouse_id ?: $order->warehouse_id, 'quantity' => $good, 'unit_cost' => $unit,
                'type' => 'production_in', 'date' => $date, 'source' => $order, 'reference' => $order->order_number,
                'batch_number' => $receipt->batch_number,
            ]);
        }

        $lines = [];
        if ($transfer > 0) {
            $lines[] = ['role' => 'inventory', 'debit' => $transfer];
        }
        if ($scrapCost > 0) {
            $lines[] = ['role' => 'scrap_loss', 'debit' => $scrapCost];
        }
        if ($transfer + $scrapCost > 0) {
            $lines[] = ['role' => 'wip', 'credit' => round($transfer + $scrapCost, 2)];
            $this->post($order, $date, "استلام منتج تام من أمر إنتاج {$order->order_number}", $lines);
        }

        $receipt->update(['quantity' => $good, 'scrap_quantity' => $scrap, 'scrap_abnormal' => $abnormal, 'unit_cost' => $unit, 'total_cost' => $transfer, 'status' => 'posted']);

        $received = (float) $order->received_quantity + $good;
        $order->update([
            'received_quantity' => $received,
            'scrap_quantity' => (float) $order->scrap_quantity + $scrap,
            'transferred_cost' => (float) $order->transferred_cost + $transfer + $scrapCost,
            'status' => $received >= (float) $order->quantity - 0.00005 ? 'completed' : 'in_progress',
            'actual_end' => $received >= (float) $order->quantity - 0.00005 ? $date->toDateString() : null,
        ]);

        return $receipt->refresh();
    }

    // ------------------------------------------------------------------ close

    /** Close the order; the cost still in WIP becomes a production variance. */
    public function close(ProductionOrder $order, ?string $date = null): ProductionOrder
    {
        $date = Carbon::parse($date ?? now());

        return DB::transaction(function () use ($order, $date) {
            $this->lock($order);
            $this->assertStatus($order, ['in_progress', 'completed'], 'يُغلق الأمر بعد بدء تنفيذه.');
            if ($order->receipts()->where('status', 'pending_qc')->exists()) {
                throw ValidationException::withMessages(['order' => 'توجد استلامات بانتظار فحص الجودة.']);
            }
            $left = round($order->wipBalance(), 2);
            if ($left != 0.0) {
                $this->post($order, $date, "إقفال أمر إنتاج {$order->order_number} (فروقات)", $left > 0
                    ? [['role' => 'production_variance', 'debit' => $left], ['role' => 'wip', 'credit' => $left]]
                    : [['role' => 'wip', 'debit' => abs($left)], ['role' => 'production_variance', 'credit' => abs($left)]]);
            }
            $order->update(['variance' => (float) $order->variance + $left, 'status' => 'closed', 'actual_end' => $order->actual_end ?? $date->toDateString()]);

            return $order;
        });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Must be the first thing a change does: two operators on one order go one after the other, and each decides on
     * the row as the other left it. The values come from the locking read, which sees what was committed while it
     * waited (refresh() may not, inside a transaction that already read something), onto the caller's own instance.
     *
     * @template T of ProductionOrder|ProductionOrderMaterial
     *
     * @param  T  $row
     * @return T
     */
    protected function lock(ProductionOrder|ProductionOrderMaterial $row): ProductionOrder|ProductionOrderMaterial
    {
        return $row->setRawAttributes($row->newQueryWithoutScopes()->whereKey($row->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), true);
    }

    protected function started(ProductionOrder $order, Carbon $date): void
    {
        if ($order->status === 'released') {
            $order->update(['status' => 'in_progress', 'actual_start' => $order->actual_start ?? $date->toDateString()]);
        }
    }

    protected function assertStatus(ProductionOrder $order, array $allowed, string $message): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['order' => $message]);
        }
    }

    protected function post(ProductionOrder $order, Carbon $date, string $description, array $lines, ?int $costCenterId = null): void
    {
        if ($costCenterId) {
            $lines = array_map(fn ($l) => isset($l['debit']) && ($l['role'] ?? '') === 'wip' ? $l + ['cost_center_id' => $costCenterId] : $l, $lines);
        }
        $this->posting->post(['date' => $date, 'description' => $description, 'source' => $order, 'lines' => $lines]);
    }
}

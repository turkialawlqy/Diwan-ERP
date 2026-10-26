<?php

namespace App\Services\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentMaintenanceSchedule;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceOrderItem;
use App\Models\Product;
use App\Services\Accounting\PostingService;
use App\Services\Documents\DocumentNumberService;
use App\Services\Inventory\DocumentStock;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance orders: opened by maintenance, parts issued directly by the
 * storekeeper (stock out at actual cost, charged to maintenance expense on
 * the equipment's cost centre), completed by ticking the serviced items.
 */
class MaintenanceService
{
    public function __construct(
        protected StockService $stock,
        protected DocumentStock $documentStock,
        protected PostingService $posting,
        protected DocumentNumberService $numbers,
        protected MaintenanceTracker $tracker,
    ) {}

    public function open(Equipment $equipment, array $data): MaintenanceOrder
    {
        $order = DB::transaction(function () use ($equipment, $data) {
            $order = MaintenanceOrder::create([
                'number' => $this->numbers->next('maintenance_order', now()),
                'equipment_id' => $equipment->id,
                'type' => $data['type'] ?? 'preventive',
                'date' => $data['date'],
                'hours_reading' => $data['hours_reading'] ?? ($equipment->usesHours() ? $equipment->current_hours : null),
                'km_reading' => $data['km_reading'] ?? ($equipment->usesKm() ? $equipment->current_km : null),
                'technician' => $data['technician'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'previous_status' => $equipment->status,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            if (! empty($data['schedule_ids'])) {
                $order->schedules()->sync($this->ownSchedules($equipment, $data['schedule_ids']));
            }
            $equipment->syncStatus();

            return $order;
        });

        return $order;
    }

    /**
     * Storekeeper issue.
     *
     * @param  array  $lines  [product_id, quantity, schedule_id?, batch_id?, serials?]
     */
    public function issue(MaintenanceOrder $order, array $lines, ?int $warehouseId = null, ?string $date = null): float
    {
        if ($order->status !== 'open') {
            throw ValidationException::withMessages(['items' => 'لا يمكن الصرف على أمر صيانة مكتمل.']);
        }

        return DB::transaction(function () use ($order, $lines, $warehouseId, $date) {
            $equipment = $order->equipment;
            $warehouseId = $this->documentStock->warehouse($warehouseId ?? $order->warehouse_id);
            $date ??= now()->toDateString();
            $total = 0.0;

            foreach ($lines as $line) {
                $quantity = (float) ($line['quantity'] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }
                $product = Product::findOrFail($line['product_id']);
                $item = MaintenanceOrderItem::create([
                    'maintenance_order_id' => $order->id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'schedule_id' => ($line['schedule_id'] ?? null) ? $this->ownSchedules($equipment, [$line['schedule_id']])[0] ?? null : null,
                    'quantity' => $quantity,
                    'issued_by' => auth()->id(),
                ]);
                $value = $this->stock->issue(['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'quantity' => $quantity,
                    'type' => 'maintenance_issue', 'date' => $date, 'source' => $item, 'reference' => $order->number,
                    'serials' => $line['serials'] ?? [], 'batch_id' => $line['batch_id'] ?? null]);
                $item->update(['unit_cost' => round($value / $quantity, 4), 'amount' => $value]);
                $total += $value;
            }

            if (! $total && ! collect($lines)->sum(fn ($l) => (float) ($l['quantity'] ?? 0))) {
                throw ValidationException::withMessages(['items' => 'أدخل صنفاً واحداً على الأقل بكمية أكبر من صفر.']);
            }

            if ($total > 0) {
                $this->posting->post([
                    'date' => $date,
                    'reference' => $order->number,
                    'description' => "صرف قطع غيار لأمر الصيانة {$order->number} - {$equipment->name} ({$equipment->code})",
                    'source' => $order,
                    'lines' => [
                        ['role' => 'maintenance_expense', 'debit' => round($total, 2), 'cost_center_id' => $equipment->cost_center_id],
                        ['role' => 'inventory', 'credit' => round($total, 2)],
                    ],
                ]);
            }

            $order->update(['parts_cost' => round((float) $order->items()->sum('amount'), 2), 'warehouse_id' => $order->warehouse_id ?? $warehouseId]);

            return round($total, 2);
        });
    }

    /**
     * Completes the order: the ticked items restart their intervals from the
     * order's reading and date. Items linked to issued parts are included.
     */
    public function complete(MaintenanceOrder $order, array $scheduleIds, array $data = []): MaintenanceOrder
    {
        if ($order->status !== 'open') {
            throw ValidationException::withMessages(['status' => 'أمر الصيانة مكتمل مسبقاً.']);
        }

        DB::transaction(function () use ($order, $scheduleIds, $data) {
            $equipment = $order->equipment;
            $hours = array_key_exists('hours_reading', $data) && $data['hours_reading'] !== null ? (float) $data['hours_reading'] : $order->hours_reading;
            $km = array_key_exists('km_reading', $data) && $data['km_reading'] !== null ? (float) $data['km_reading'] : $order->km_reading;
            $date = $data['date'] ?? now()->toDateString();

            $ids = $this->ownSchedules($equipment, array_merge($scheduleIds, $order->items()->whereNotNull('schedule_id')->pluck('schedule_id')->all()));
            $sync = [];
            foreach (EquipmentMaintenanceSchedule::whereIn('id', $ids)->get() as $schedule) {
                $sync[$schedule->id] = ['was_overdue' => $this->tracker->status($schedule, $equipment)['state'] === 'overdue'];
                $schedule->forceFill([
                    'last_done_date' => $date,
                    'last_done_hours' => $hours ?? $equipment->current_hours,
                    'last_done_km' => $km ?? $equipment->current_km,
                    'alert_state' => 'ok',
                ])->save();
            }
            $order->schedules()->sync($sync);

            // A reading taken at the workshop moves the meters forward too.
            if (($hours !== null && $hours > $equipment->current_hours) || ($km !== null && $km > $equipment->current_km)) {
                $equipment->forceFill([
                    'current_hours' => max($equipment->current_hours, (float) $hours),
                    'current_km' => max($equipment->current_km, (float) $km),
                ])->save();
            }

            $order->update(['status' => 'completed', 'completed_at' => now(), 'hours_reading' => $hours, 'km_reading' => $km,
                'notes' => $data['notes'] ?? $order->notes]);
            $equipment->syncStatus();
        });

        $this->tracker->refresh($order->equipment->fresh());

        return $order->fresh();
    }

    /** Ids that really belong to this equipment (never trust posted ids). */
    protected function ownSchedules(Equipment $equipment, array $ids): array
    {
        return EquipmentMaintenanceSchedule::where('equipment_id', $equipment->id)->whereIn('id', array_filter($ids))->pluck('id')->all();
    }

    /** Copies the maintenance plan of another equipment (same model). */
    public function copySchedules(Equipment $from, Equipment $to): int
    {
        $count = 0;
        foreach ($from->schedules()->get() as $schedule) {
            $to->schedules()->create(array_merge(
                $schedule->only(['product_id', 'name', 'default_quantity', 'interval_hours', 'interval_km', 'interval_days', 'warn_hours', 'warn_km', 'warn_days', 'is_active']),
                ['last_done_date' => now()->toDateString(), 'last_done_hours' => $to->current_hours, 'last_done_km' => $to->current_km, 'alert_state' => 'ok'],
            ));
            $count++;
        }

        return $count;
    }
}

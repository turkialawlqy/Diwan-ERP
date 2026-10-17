<?php

namespace App\Services\Equipment;

use App\Models\AssetDepreciation;
use App\Models\Equipment;
use App\Models\EquipmentBilling;
use App\Models\EquipmentReading;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceOrderItem;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Management reports for the equipment fleet. Amounts are in base currency
 * and attributed to the period by the billed period's end / the issue date.
 */
class EquipmentReports
{
    public function __construct(protected MaintenanceTracker $tracker) {}

    /** Revenue, internal recovery and costs per equipment, with net result. */
    public function profitability(Carbon $from, Carbon $to): array
    {
        $billing = fn (string $direction, ?string $type = null) => EquipmentBilling::where('direction', $direction)
            ->whereBetween('period_to', [$from->toDateString(), $to->toDateString()])
            ->when($type, fn ($q) => $q->whereHas('rental', fn ($r) => $r->where('type', $type)))
            ->groupBy('equipment_id')->selectRaw('equipment_id, SUM(amount) s, SUM(quantity) q')->get()->keyBy('equipment_id');

        $revenue = $billing('out', 'customer');
        $recovery = $billing('out', 'project');
        $rentIn = $billing('in');
        $maintenance = MaintenanceOrderItem::query()
            ->join('maintenance_orders', 'maintenance_orders.id', '=', 'maintenance_order_items.maintenance_order_id')
            ->whereBetween(DB::raw('DATE(maintenance_order_items.created_at)'), [$from->toDateString(), $to->toDateString()])
            ->groupBy('maintenance_orders.equipment_id')
            ->selectRaw('maintenance_orders.equipment_id, SUM(maintenance_order_items.amount) s')->get()->keyBy('equipment_id');
        $depreciation = AssetDepreciation::whereBetween('period', [(int) $from->format('Ym'), (int) $to->format('Ym')])
            ->groupBy('fixed_asset_id')->selectRaw('fixed_asset_id, SUM(amount) s')->pluck('s', 'fixed_asset_id');
        $hours = $this->workedHours($from, $to);

        $rows = Equipment::orderBy('code')->get()->map(function (Equipment $e) use ($revenue, $recovery, $rentIn, $maintenance, $depreciation, $hours) {
            $row = [
                'id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'category' => $e->category, 'ownership' => $e->ownership,
                'revenue' => round((float) ($revenue[$e->id]->s ?? 0), 2),
                'recovery' => round((float) ($recovery[$e->id]->s ?? 0), 2),
                'maintenance' => round((float) ($maintenance[$e->id]->s ?? 0), 2),
                'rent_in' => round((float) ($rentIn[$e->id]->s ?? 0), 2),
                'depreciation' => round((float) ($e->fixed_asset_id ? ($depreciation[$e->fixed_asset_id] ?? 0) : 0), 2),
                'hours' => round((float) ($hours[$e->id] ?? 0), 1),
            ];
            $row['income'] = round($row['revenue'] + $row['recovery'], 2);
            $row['costs'] = round($row['maintenance'] + $row['rent_in'] + $row['depreciation'], 2);
            $row['net'] = round($row['income'] - $row['costs'], 2);
            $row['margin'] = $row['income'] > 0 ? round($row['net'] / $row['income'] * 100, 1) : null;
            $row['cost_per_hour'] = $row['hours'] > 0 ? round($row['costs'] / $row['hours'], 2) : null;

            return $row;
        })->filter(fn ($r) => $r['income'] || $r['costs'] || $r['hours'])->values()->all();

        return ['rows' => $rows, 'totals' => $this->totals($rows, ['revenue', 'recovery', 'income', 'maintenance', 'rent_in', 'depreciation', 'costs', 'net', 'hours'])];
    }

    /** Worked hours against available hours (days × standard day), idle and workshop days. */
    public function utilization(Carbon $from, Carbon $to, float $standardHours = 10): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $hours = $this->workedHours($from, $to);
        $workDays = EquipmentReading::whereBetween('date', [$from->toDateString(), $to->toDateString()])->where('worked_hours', '>', 0)
            ->groupBy('equipment_id')->selectRaw('equipment_id, COUNT(*) c')->pluck('c', 'equipment_id');
        $orders = MaintenanceOrder::whereDate('date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhereDate('completed_at', '>=', $from->toDateString()))
            ->get(['equipment_id', 'date', 'completed_at']);

        $rows = Equipment::where('status', '!=', 'out_of_service')->orderBy('code')->get()->map(function (Equipment $e) use ($from, $to, $days, $hours, $workDays, $orders, $standardHours) {
            $downtime = $orders->where('equipment_id', $e->id)->sum(function ($o) use ($from, $to) {
                $start = Carbon::parse($o->date)->max($from);
                $end = ($o->completed_at ? Carbon::parse($o->completed_at)->startOfDay() : $to->copy())->min($to);

                return $start->lte($end) ? (int) $start->diffInDays($end) + 1 : 0;
            });
            $worked = round((float) ($hours[$e->id] ?? 0), 1);
            $available = $days * $standardHours;

            return [
                'id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'category' => $e->category, 'status' => $e->status,
                'worked_hours' => $worked, 'available_hours' => $available,
                'utilization' => $available > 0 ? round($worked / $available * 100, 1) : 0,
                'work_days' => (int) ($workDays[$e->id] ?? 0),
                'downtime_days' => (int) $downtime,
                'idle_days' => max(0, $days - (int) ($workDays[$e->id] ?? 0) - (int) $downtime),
            ];
        })->values()->all();

        return ['rows' => $rows, 'days' => $days, 'standard_hours' => $standardHours,
            'totals' => $this->totals($rows, ['worked_hours', 'available_hours', 'work_days', 'downtime_days', 'idle_days'])];
    }

    /** Periodic items serviced on time vs late, what is overdue now, cost per hour/km. */
    public function compliance(Carbon $from, Carbon $to): array
    {
        $done = DB::table('maintenance_order_schedules')
            ->join('maintenance_orders', 'maintenance_orders.id', '=', 'maintenance_order_schedules.maintenance_order_id')
            ->where('maintenance_orders.company_id', app(TenantManager::class)->getTenantId())
            ->where('maintenance_orders.status', 'completed')
            ->whereBetween(DB::raw('DATE(maintenance_orders.completed_at)'), [$from->toDateString(), $to->toDateString()])
            ->groupBy('maintenance_orders.equipment_id')
            ->selectRaw('maintenance_orders.equipment_id, COUNT(*) total, SUM(CASE WHEN maintenance_order_schedules.was_overdue THEN 1 ELSE 0 END) late')
            ->get()->keyBy('equipment_id');
        $due = $this->tracker->dueList()->groupBy(fn ($row) => $row['equipment']->id);
        $parts = MaintenanceOrderItem::query()->join('maintenance_orders', 'maintenance_orders.id', '=', 'maintenance_order_items.maintenance_order_id')
            ->whereBetween(DB::raw('DATE(maintenance_order_items.created_at)'), [$from->toDateString(), $to->toDateString()])
            ->groupBy('maintenance_orders.equipment_id')->selectRaw('maintenance_orders.equipment_id, SUM(maintenance_order_items.amount) s')->pluck('s', 'equipment_id');
        $orders = MaintenanceOrder::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('equipment_id', 'type')->selectRaw('equipment_id, type, COUNT(*) c')->get()->groupBy('equipment_id');
        $meters = EquipmentReading::whereBetween('date', [$from->toDateString(), $to->toDateString()])->groupBy('equipment_id')
            ->selectRaw('equipment_id, MAX(hours_reading) - MIN(hours_reading) h, MAX(km_reading) - MIN(km_reading) k')->get()->keyBy('equipment_id');

        $rows = Equipment::orderBy('code')->get()->map(function (Equipment $e) use ($done, $due, $parts, $orders, $meters) {
            $total = (int) ($done[$e->id]->total ?? 0);
            $late = (int) ($done[$e->id]->late ?? 0);
            $cost = round((float) ($parts[$e->id] ?? 0), 2);
            $h = (float) ($meters[$e->id]->h ?? 0);
            $k = (float) ($meters[$e->id]->k ?? 0);
            $byType = ($orders[$e->id] ?? collect())->pluck('c', 'type');

            return [
                'id' => $e->id, 'code' => $e->code, 'name' => $e->name,
                'serviced' => $total, 'on_time' => $total - $late, 'late' => $late,
                'on_time_rate' => $total ? round(($total - $late) / $total * 100, 1) : null,
                'overdue_now' => ($due[$e->id] ?? collect())->where('status.state', 'overdue')->count(),
                'due_soon_now' => ($due[$e->id] ?? collect())->where('status.state', 'due_soon')->count(),
                'preventive' => (int) ($byType['preventive'] ?? 0), 'corrective' => (int) ($byType['corrective'] ?? 0),
                'parts_cost' => $cost,
                'cost_per_hour' => $h > 0 ? round($cost / $h, 2) : null,
                'cost_per_km' => $k > 0 ? round($cost / $k, 3) : null,
            ];
        })->filter(fn ($r) => $r['serviced'] || $r['overdue_now'] || $r['due_soon_now'] || $r['parts_cost'] || $r['preventive'] || $r['corrective'])->values()->all();

        return ['rows' => $rows, 'totals' => $this->totals($rows, ['serviced', 'on_time', 'late', 'overdue_now', 'due_soon_now', 'preventive', 'corrective', 'parts_cost'])];
    }

    /** Spare parts issued, by item and by equipment. */
    public function parts(Carbon $from, Carbon $to): array
    {
        $rows = MaintenanceOrderItem::query()
            ->join('maintenance_orders', 'maintenance_orders.id', '=', 'maintenance_order_items.maintenance_order_id')
            ->join('equipment', 'equipment.id', '=', 'maintenance_orders.equipment_id')
            ->join('products', 'products.id', '=', 'maintenance_order_items.product_id')
            ->whereBetween(DB::raw('DATE(maintenance_order_items.created_at)'), [$from->toDateString(), $to->toDateString()])
            ->groupBy('products.id', 'products.name_ar', 'products.sku', 'equipment.id', 'equipment.code', 'equipment.name')
            ->selectRaw('products.id product_id, products.name_ar product, products.sku, equipment.id equipment_id, equipment.code, equipment.name equipment,
                SUM(maintenance_order_items.quantity) quantity, SUM(maintenance_order_items.amount) amount, COUNT(DISTINCT maintenance_orders.id) orders')
            ->orderByDesc('amount')->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product' => $r->product, 'sku' => $r->sku, 'equipment_id' => $r->equipment_id, 'code' => $r->code,
                'equipment' => $r->equipment, 'quantity' => round((float) $r->quantity, 3), 'amount' => round((float) $r->amount, 2), 'orders' => (int) $r->orders])
            ->all();

        $byProduct = collect($rows)->groupBy('product_id')->map(fn ($g) => ['product' => $g[0]['product'], 'sku' => $g[0]['sku'],
            'quantity' => round($g->sum('quantity'), 3), 'amount' => round($g->sum('amount'), 2), 'equipment_count' => $g->count()])->sortByDesc('amount')->values()->all();

        return ['rows' => $rows, 'by_product' => $byProduct, 'totals' => $this->totals($rows, ['quantity', 'amount'])];
    }

    protected function workedHours(Carbon $from, Carbon $to)
    {
        return EquipmentReading::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('equipment_id')->selectRaw('equipment_id, SUM(worked_hours) s')->pluck('s', 'equipment_id');
    }

    protected function totals(array $rows, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($k) => [$k => round(collect($rows)->sum($k), 2)])->all();
    }
}

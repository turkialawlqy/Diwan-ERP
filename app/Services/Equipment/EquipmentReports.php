<?php

namespace App\Services\Equipment;

use App\Models\AssetDepreciation;
use App\Models\Equipment;
use App\Models\EquipmentBilling;
use App\Models\EquipmentPreparation;
use App\Models\EquipmentReading;
use App\Models\EquipmentRental;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceOrderItem;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /** The daily rows list at most this many equipment-days; wider ranges stop with a message. */
    public const ATTENDANCE_MAX_DAYS = 62;

    public const STATUS_LABELS = ['worked' => 'عملت', 'prepared' => 'محضَّرة', 'maintenance' => 'في الصيانة', 'deployed' => 'مُسنَدة بلا عمل', 'idle' => 'متوقفة'];

    /** Equipment × day: worked (with time in and out), prepared only, in the workshop, deployed without work, or idle. */
    public function attendance(Carbon $from, Carbon $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $max = self::ATTENDANCE_MAX_DAYS;
        if ($days > $max) {
            throw ValidationException::withMessages(['to' => "تقرير الحضور اليومي لا يتجاوز {$max} يومًا؛ قصّر الفترة."]);
        }
        $f = $from->toDateString();
        $t = $to->toDateString();
        $equipment = Equipment::where('status', '!=', 'out_of_service')->orderBy('code')->get(['id', 'code', 'name', 'category']);
        $readings = EquipmentReading::whereBetween('date', [$f, $t])->get()->groupBy('equipment_id')->map(fn ($g) => $g->keyBy(fn ($r) => $r->date->toDateString()));
        $preps = EquipmentPreparation::whereBetween('prep_date', [$f, $t])->get()->groupBy('equipment_id')->map(fn ($g) => $g->keyBy(fn ($p) => $p->prep_date->toDateString()));
        $orders = MaintenanceOrder::whereDate('date', '<=', $t)->where(fn ($q) => $q->whereNull('completed_at')->orWhereDate('completed_at', '>=', $f))->get(['equipment_id', 'date', 'completed_at'])->groupBy('equipment_id');
        $rentals = EquipmentRental::with(['customer:id,name', 'project:id,name'])->whereDate('start_date', '<=', $t)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $f))->get()->groupBy('equipment_id');

        $rows = [];
        $summary = [];
        foreach ($equipment as $e) {
            $sum = ['id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'worked' => 0, 'prepared' => 0, 'maintenance' => 0, 'deployed' => 0, 'idle' => 0, 'hours' => 0.0];
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $day = $d->toDateString();
                $reading = $readings[$e->id][$day] ?? null;
                $prep = $preps[$e->id][$day] ?? null;
                $inShop = ($orders[$e->id] ?? collect())->contains(fn ($o) => Carbon::parse($o->date)->lte($d) && (! $o->completed_at || Carbon::parse($o->completed_at)->startOfDay()->gte($d)));
                $rental = ($rentals[$e->id] ?? collect())->first(fn ($r) => Carbon::parse($r->start_date)->lte($d) && (! $r->end_date || Carbon::parse($r->end_date)->gte($d)));
                $status = ($reading && ($reading->worked_hours > 0 || $reading->check_in)) ? 'worked' : ($inShop ? 'maintenance' : ($prep ? 'prepared' : ($rental ? 'deployed' : 'idle')));
                $sum[$status]++;
                $sum['hours'] += (float) ($reading?->worked_hours ?? 0);
                $rows[] = [
                    'id' => $e->id, 'date' => $day, 'code' => $e->code, 'name' => $e->name,
                    'status' => self::STATUS_LABELS[$status], 'state' => $status,
                    'check_in' => $reading?->check_in ? substr($reading->check_in, 0, 5) : null,
                    'check_out' => $reading?->check_out ? substr($reading->check_out, 0, 5) : null,
                    'worked_hours' => $reading ? round((float) $reading->worked_hours, 2) : null,
                    'prepared' => $prep ? ($prep->status === 'ready' ? 'جاهزة' : 'تحتاج متابعة') : null,
                    'operator' => $reading?->operator ?: $prep?->operator,
                    'site' => $rental ? ($rental->customer?->name ?: $rental->project?->name ?: $rental->site) : null,
                ];
            }
            $sum['hours'] = round($sum['hours'], 1);
            $summary[] = $sum;
        }

        return ['rows' => $rows, 'by_equipment' => $summary, 'days' => $days,
            'totals' => ['worked_hours' => round(collect($rows)->sum('worked_hours'), 2)]];
    }

    /** Every maintenance order of the period with its downtime, and per equipment: orders, breakdowns, downtime, average repair time. */
    public function maintenanceLog(Carbon $from, Carbon $to): array
    {
        $orders = MaintenanceOrder::with('equipment:id,code,name')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderByDesc('date')->orderByDesc('id')->get();
        $items = MaintenanceOrderItem::whereIn('maintenance_order_id', $orders->pluck('id'))->groupBy('maintenance_order_id')->selectRaw('maintenance_order_id, SUM(amount) s')->pluck('s', 'maintenance_order_id');
        $now = now()->startOfDay();

        $rows = $orders->map(function ($o) use ($items, $now) {
            $start = Carbon::parse($o->date)->startOfDay();
            $end = $o->completed_at ? Carbon::parse($o->completed_at)->startOfDay() : $now;

            return [
                'id' => $o->equipment_id, 'number' => $o->number, 'code' => $o->equipment?->code, 'name' => $o->equipment?->name,
                'type' => $o->type === 'preventive' ? 'وقائية' : 'إصلاح', 'date' => $start->toDateString(),
                'completed_at' => $o->completed_at ? Carbon::parse($o->completed_at)->toDateString() : null,
                'downtime_days' => $end->gte($start) ? (int) $start->diffInDays($end) + 1 : 0,
                'technician' => $o->technician, 'status' => $o->status === 'completed' ? 'منفَّذ' : 'مفتوح',
                'parts_cost' => round((float) ($items[$o->id] ?? $o->parts_cost ?? 0), 2), 'notes' => $o->notes,
            ];
        })->all();

        $byEquipment = collect($rows)->groupBy('code')->map(function ($g) {
            $closed = $g->where('status', 'منفَّذ');

            return ['id' => $g[0]['id'], 'code' => $g[0]['code'], 'name' => $g[0]['name'], 'orders' => $g->count(),
                'corrective' => $g->where('type', 'إصلاح')->count(), 'preventive' => $g->where('type', 'وقائية')->count(),
                'open' => $g->where('status', 'مفتوح')->count(), 'downtime_days' => $g->sum('downtime_days'),
                'avg_repair_days' => $closed->count() ? round($closed->avg('downtime_days'), 1) : null, 'parts_cost' => round($g->sum('parts_cost'), 2)];
        })->sortByDesc('downtime_days')->values()->all();

        return ['rows' => $rows, 'by_equipment' => $byEquipment, 'totals' => $this->totals($rows, ['downtime_days', 'parts_cost'])];
    }

    /** Daily preparation discipline: days prepared against days worked, and the days worked with no preparation. */
    public function preparation(Carbon $from, Carbon $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $preps = EquipmentPreparation::whereBetween('prep_date', [$f, $t])->get()->groupBy('equipment_id');
        $worked = EquipmentReading::whereBetween('date', [$f, $t])->where('worked_hours', '>', 0)->get()->groupBy('equipment_id');

        $rows = Equipment::orderBy('code')->get()->map(function (Equipment $e) use ($preps, $worked) {
            $p = $preps[$e->id] ?? collect();
            $w = $worked[$e->id] ?? collect();
            $prepDays = $p->map(fn ($x) => $x->prep_date->toDateString())->unique();
            $workDays = $w->map(fn ($x) => $x->date->toDateString())->unique();
            $unprepared = $workDays->diff($prepDays)->count();

            return ['id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'prepared_days' => $prepDays->count(),
                'ready' => $p->where('status', 'ready')->count(), 'needs_attention' => $p->where('status', 'needs_attention')->count(),
                'work_days' => $workDays->count(), 'worked_unprepared' => $unprepared,
                'rate' => $workDays->count() ? round(($workDays->count() - $unprepared) / $workDays->count() * 100, 1) : null,
                'operators' => $p->pluck('operator')->filter()->unique()->implode('، ')];
        })->filter(fn ($r) => $r['prepared_days'] || $r['work_days'])->values()->all();

        return ['rows' => $rows, 'totals' => $this->totals($rows, ['prepared_days', 'ready', 'needs_attention', 'work_days', 'worked_unprepared'])];
    }

    /** Per operator: days, hours and equipment worked; and the assignment (rental / project deployment) history of the period. */
    public function operators(Carbon $from, Carbon $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $readings = EquipmentReading::whereBetween('date', [$f, $t])->whereNotNull('operator')->where('operator', '!=', '')->get();
        $rows = $readings->groupBy(fn ($r) => trim($r->operator))->map(fn ($g, $name) => [
            'operator' => $name, 'days' => $g->where('worked_hours', '>', 0)->count(), 'hours' => round($g->sum('worked_hours'), 1),
            'equipment_count' => $g->pluck('equipment_id')->unique()->count(),
            'equipment' => $g->pluck('equipment_id')->unique()->map(fn ($id) => Equipment::find($id)?->code)->filter()->implode('، '),
        ])->sortByDesc('hours')->values()->all();

        $deployments = EquipmentRental::with(['equipment:id,code,name', 'customer:id,name', 'project:id,name'])->whereDate('start_date', '<=', $t)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $f))->orderBy('start_date')->get()->map(function ($r) use ($from, $to) {
                $start = Carbon::parse($r->start_date)->max($from);
                $end = ($r->end_date ? Carbon::parse($r->end_date) : $to->copy())->min($to);

                return ['number' => $r->number, 'code' => $r->equipment?->code, 'name' => $r->equipment?->name, 'id' => $r->equipment_id,
                    'type' => $r->type === 'customer' ? 'عميل' : 'مشروع', 'to' => $r->customer?->name ?: $r->project?->name, 'site' => $r->site,
                    'start_date' => Carbon::parse($r->start_date)->toDateString(), 'end_date' => $r->end_date ? Carbon::parse($r->end_date)->toDateString() : null,
                    'days_in_period' => $start->lte($end) ? (int) $start->diffInDays($end) + 1 : 0, 'status' => $r->status === 'active' ? 'نشط' : 'مُنهى'];
            })->all();

        return ['rows' => $rows, 'deployments' => $deployments, 'totals' => $this->totals($rows, ['days', 'hours'])];
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

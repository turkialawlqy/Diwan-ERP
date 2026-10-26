<?php

namespace App\Services\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentMaintenanceSchedule;
use App\Models\User;
use App\Notifications\ErpAlert;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Periodic maintenance status. An item is due when ANY of its intervals
 * (hours, km, days) is reached since it was last done, and "due soon" when
 * it is within the warning margin of one of them.
 */
class MaintenanceTracker
{
    public const LEVELS = ['ok' => 0, 'due_soon' => 1, 'overdue' => 2];

    /** Default warning margin when none is set: 10% of the interval. */
    protected const DEFAULT_WARN = 0.1;

    public function status(EquipmentMaintenanceSchedule $schedule, ?Equipment $equipment = null, ?Carbon $today = null): array
    {
        $equipment ??= $schedule->equipment;
        $today ??= now()->startOfDay();
        $since = $schedule->last_done_date ?? $schedule->created_at ?? $today;
        $checks = [];

        if ($schedule->interval_hours && $equipment->usesHours()) {
            $base = $schedule->last_done_hours ?? 0;
            $used = max(0, $equipment->current_hours - $base);
            $checks['hours'] = $this->check($used, $schedule->interval_hours, $schedule->warn_hours, $base + $schedule->interval_hours);
        }
        if ($schedule->interval_km && $equipment->usesKm()) {
            $base = $schedule->last_done_km ?? 0;
            $used = max(0, $equipment->current_km - $base);
            $checks['km'] = $this->check($used, $schedule->interval_km, $schedule->warn_km, $base + $schedule->interval_km);
        }
        if ($schedule->interval_days) {
            $used = max(0, (int) Carbon::parse($since)->startOfDay()->diffInDays($today->copy()->startOfDay(), false));
            $check = $this->check($used, $schedule->interval_days, $schedule->warn_days, null);
            $check['due_at'] = Carbon::parse($since)->addDays($schedule->interval_days)->toDateString();
            $checks['days'] = $check;
        }

        $state = 'ok';
        $percent = 0;
        foreach ($checks as $check) {
            if (self::LEVELS[$check['state']] > self::LEVELS[$state]) {
                $state = $check['state'];
            }
            $percent = max($percent, $check['percent']);
        }

        return ['state' => $state, 'percent' => $percent, 'checks' => $checks];
    }

    protected function check(float $used, float $interval, ?float $warn, ?float $dueAt): array
    {
        $warn ??= $interval * self::DEFAULT_WARN;
        $remaining = $interval - $used;
        $state = $remaining <= 0 ? 'overdue' : ($remaining <= $warn ? 'due_soon' : 'ok');

        return ['used' => round($used, 1), 'interval' => $interval, 'remaining' => round($remaining, 1), 'due_at' => $dueAt,
            'percent' => (int) min(999, round($used / $interval * 100)), 'state' => $state];
    }

    /**
     * Re-evaluates an equipment's items; alerts once per worsening, so a
     * reading entered every day does not repeat the same notification.
     */
    public function refresh(Equipment $equipment): void
    {
        $equipment->loadMissing('schedules');
        $worse = [];

        foreach ($equipment->schedules->where('is_active', true) as $schedule) {
            $state = $this->status($schedule, $equipment)['state'];
            if ($state === $schedule->alert_state) {
                continue;
            }
            if (self::LEVELS[$state] > self::LEVELS[$schedule->alert_state]) {
                $worse[] = [$schedule, $state];
            }
            $schedule->forceFill(['alert_state' => $state])->saveQuietly();
        }

        if (! $worse) {
            return;
        }

        $overdue = collect($worse)->where(1, 'overdue')->map(fn ($w) => $w[0]->name)->all();
        $soon = collect($worse)->where(1, 'due_soon')->map(fn ($w) => $w[0]->name)->all();
        $title = ($overdue ? 'صيانة متأخرة: ' : 'صيانة تستحق قريباً: ').$equipment->name." ({$equipment->code})";
        $body = trim(($overdue ? 'متأخرة: '.implode('، ', $overdue).'. ' : '').($soon ? 'قريباً: '.implode('، ', $soon).'.' : ''));
        $this->notify('equipment_maintenance', $title, $body, route('equipment.show', $equipment->id, false), $overdue ? 'danger' : 'warning');
    }

    public function notify(string $kind, string $title, string $body, ?string $url, string $level): void
    {
        $users = User::where('company_id', app(TenantManager::class)->getTenantId())->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->can('equipment.maintenance.view'));
        foreach ($users as $user) {
            $user->notify(new ErpAlert($kind, $title, $body, $url, $level));
        }
    }

    /**
     * Active items that are due or due soon, worst first.
     *
     * @return Collection<int, array{schedule: EquipmentMaintenanceSchedule, equipment: Equipment, status: array}>
     */
    public function dueList(bool $includeSoon = true): Collection
    {
        return EquipmentMaintenanceSchedule::with(['equipment', 'product:id,name_ar,sku'])
            ->where('is_active', true)
            ->whereHas('equipment', fn ($q) => $q->where('status', '!=', 'out_of_service'))
            ->get()
            ->map(fn ($s) => ['schedule' => $s, 'equipment' => $s->equipment, 'status' => $this->status($s)])
            ->filter(fn ($row) => $row['status']['state'] === 'overdue' || ($includeSoon && $row['status']['state'] === 'due_soon'))
            ->sortByDesc(fn ($row) => [self::LEVELS[$row['status']['state']], $row['status']['percent']])
            ->values();
    }
}

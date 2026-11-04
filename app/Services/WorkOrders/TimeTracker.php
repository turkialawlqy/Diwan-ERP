<?php

namespace App\Services\WorkOrders;

use App\Models\Employee;
use App\Models\TimeEntry;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Timers and manual hours of technicians; the labour cost is frozen when an entry closes. */
class TimeTracker
{
    /** Standard monthly hours used when an employee has no explicit cost rate. */
    public const MONTH_HOURS = 208;

    public function costRate(Employee $employee): float
    {
        if ($employee->labour_cost_rate !== null) {
            return (float) $employee->labour_cost_rate;
        }

        return (float) $employee->total_salary > 0 ? round((float) $employee->total_salary / self::MONTH_HOURS, 4) : 0.0;
    }

    public function running(Employee $employee): ?TimeEntry
    {
        return TimeEntry::where('employee_id', $employee->id)->whereNull('ended_at')->first();
    }

    public function start(WorkOrder $order, Employee $employee, ?Carbon $at = null): TimeEntry
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['status' => 'أمر العمل مغلق.']);
        }

        return DB::transaction(function () use ($order, $employee, $at) {
            Employee::whereKey($employee->id)->lockForUpdate()->first(); // one timer at a time even with two taps
            if ($other = $this->running($employee)) {
                $number = $other->work_order_id ? WorkOrder::whereKey($other->work_order_id)->value('number') : null;
                throw ValidationException::withMessages(['timer' => 'لديك مؤقت يعمل بالفعل'.($number ? " على الأمر {$number}" : '').'؛ أوقفه أولًا.']);
            }

            $entry = TimeEntry::create(['work_order_id' => $order->id, 'employee_id' => $employee->id, 'started_at' => $at ?? now(), 'source' => 'timer', 'billable' => true]);
            if (in_array($order->status, ['new', 'scheduled', 'on_hold'], true)) {
                $order->update(['status' => 'in_progress', 'started_at' => $order->started_at ?? ($at ?? now())]);
            }

            return $entry;
        });
    }

    public function stop(TimeEntry $entry, ?Carbon $at = null, bool $auto = false): TimeEntry
    {
        if (! $entry->isRunning()) {
            throw ValidationException::withMessages(['timer' => 'المؤقت متوقف بالفعل.']);
        }
        $end = $at ?? now();
        $minutes = max(0, (int) round($entry->started_at->diffInSeconds($end, true) / 60));
        $entry->update([
            'ended_at' => $end, 'minutes' => $minutes, 'auto_closed' => $auto,
            'labour_cost' => round($minutes / 60 * $this->costRate($entry->employee), 2),
        ]);

        return $entry;
    }

    /** The same technician cannot be billed twice for the same minutes (another entry or his running timer). */
    protected function assertNoOverlap(int $employeeId, Carbon $start, Carbon $end, ?int $ignore = null): void
    {
        $clash = TimeEntry::where('employee_id', $employeeId)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))
            ->where('started_at', '<', $end)->where(fn ($q) => $q->whereNull('ended_at')->orWhere(fn ($w) => $w->where('ended_at', '>', $start)->where('minutes', '>', 0)))->first(); // a timer stopped after seconds bills nothing
        if ($clash) {
            throw ValidationException::withMessages(['minutes' => 'لهذا الفني وقت مسجّل يتداخل مع هذه المدة (من '.$clash->started_at->format('H:i').').']);
        }
    }

    /** Hours entered after the fact (office, or a technician who forgot the timer). */
    public function addManual(WorkOrder $order, Employee $employee, int $minutes, ?Carbon $startedAt = null, bool $billable = true, ?string $note = null): TimeEntry
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['status' => 'أمر العمل مغلق.']);
        }
        if ($minutes < 1 || $minutes > 24 * 60) {
            throw ValidationException::withMessages(['minutes' => 'المدة من دقيقة إلى 24 ساعة.']);
        }
        $start = $startedAt ?? now()->subMinutes($minutes);
        $this->assertNoOverlap($employee->id, $start, $start->copy()->addMinutes($minutes));
        $order->voidSignature();

        return TimeEntry::create([
            'work_order_id' => $order->id, 'employee_id' => $employee->id, 'started_at' => $start, 'ended_at' => $start->copy()->addMinutes($minutes), 'minutes' => $minutes,
            'billable' => $billable, 'source' => 'manual', 'labour_cost' => round($minutes / 60 * $this->costRate($employee), 2), 'note' => $note,
        ]);
    }

    /** Office correction of a closed entry. */
    public function edit(TimeEntry $entry, int $minutes, bool $billable): TimeEntry
    {
        if ($entry->isRunning()) {
            throw ValidationException::withMessages(['timer' => 'أوقف المؤقت أولًا.']);
        }
        // Unscoped: an order the user cannot see is still closed (never «no order, so editable»).
        $order = WorkOrder::withoutGlobalScopes()->find($entry->work_order_id);
        if (! $order || ! $order->isOpen()) {
            throw ValidationException::withMessages(['status' => 'أمر العمل مغلق.']);
        }
        $this->assertNoOverlap($entry->employee_id, $entry->started_at, $entry->started_at->copy()->addMinutes($minutes), $entry->id);
        $order->voidSignature();
        $entry->update(['minutes' => $minutes, 'billable' => $billable, 'ended_at' => $entry->started_at->copy()->addMinutes($minutes), 'labour_cost' => round($minutes / 60 * $this->costRate($entry->employee), 2)]);

        return $entry;
    }

    /** Close timers somebody forgot (running longer than the limit); they end at the limit and are flagged. */
    public function closeStale(int $hours): int
    {
        $n = 0;
        TimeEntry::with('employee')->whereNull('ended_at')->where('started_at', '<', now()->subHours($hours))->get()->each(function (TimeEntry $e) use ($hours, &$n) {
            $this->stop($e, $e->started_at->copy()->addHours($hours), true);
            $n++;
        });

        return $n;
    }

    /** @return array{minutes:int,billable_minutes:int,cost:float} */
    public function totals(WorkOrder $order): array
    {
        $rows = $order->timeEntries()->whereNotNull('ended_at')->get();

        return [
            'minutes' => (int) $rows->sum('minutes'),
            'billable_minutes' => (int) $rows->where('billable', true)->sum('minutes'),
            'cost' => round((float) $rows->sum('labour_cost'), 2),
        ];
    }
}

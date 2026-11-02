<?php

namespace App\Services\WorkOrders;

use App\Models\Booking;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\ErpAlert;
use App\Services\Documents\DocumentNumberService;
use App\Services\Support\SlaService;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Creating, assigning and closing work orders. */
class WorkOrderService
{
    public function __construct(
        protected DocumentNumberService $numbers,
        protected TimeTracker $time,
        protected PartsService $parts,
        protected WorkOrderSettings $settings,
        protected SlaService $sla,
    ) {}

    /**
     * @param  array  $data  customer_id (or from ticket_id / booking_id), title, work_type_id?, description?, site_address?, site_phone?, priority?, scheduled_start?, scheduled_end?,
     *                       assigned_to?, crew? [employee ids], tasks? [titles], warehouse_id?, equipment_id?, project_id?, subscription_id?
     */
    public function create(array $data, ?User $user = null): WorkOrder
    {
        $ticket = ! empty($data['ticket_id']) ? Ticket::findOrFail($data['ticket_id']) : null;
        $booking = ! empty($data['booking_id']) ? Booking::findOrFail($data['booking_id']) : null;
        $customerId = $data['customer_id'] ?? $ticket?->customer_id ?? $booking?->customer_id;
        if (! $customerId) {
            throw ValidationException::withMessages(['customer_id' => 'اختر العميل.']);
        }

        return DB::transaction(function () use ($data, $user, $ticket, $booking, $customerId) {
            $start = ! empty($data['scheduled_start']) ? Carbon::parse($data['scheduled_start']) : ($booking?->starts_at);
            $order = WorkOrder::create([
                'number' => $this->numbers->next('work_order', $start ?? now()),
                'customer_id' => $customerId,
                'ticket_id' => $ticket?->id,
                'booking_id' => $booking?->id,
                'subscription_id' => $data['subscription_id'] ?? $ticket?->subscription_id,
                'project_id' => $data['project_id'] ?? $ticket?->project_id,
                'equipment_id' => $data['equipment_id'] ?? $ticket?->equipment_id,
                'work_type_id' => $data['work_type_id'] ?? null,
                'title' => $data['title'] ?? $ticket?->subject ?? 'أمر عمل',
                'description' => $data['description'] ?? $ticket?->description,
                'site_address' => $data['site_address'] ?? null,
                'site_phone' => $data['site_phone'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'new',
                'scheduled_start' => $start,
                'scheduled_end' => ! empty($data['scheduled_end']) ? Carbon::parse($data['scheduled_end']) : ($booking?->ends_at),
                'assigned_to' => $data['assigned_to'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'created_by' => $user?->id,
            ]);
            $this->syncCrew($order, $data['crew'] ?? []);
            $this->syncTasks($order, $data['tasks'] ?? []);
            $this->refreshScheduled($order);

            return $order;
        });
    }

    public function update(WorkOrder $order, array $data): WorkOrder
    {
        $this->assertOpen($order);

        return DB::transaction(function () use ($order, $data) {
            $order->update(collect($data)->only(['customer_id', 'work_type_id', 'title', 'description', 'site_address', 'site_phone', 'priority', 'scheduled_start', 'scheduled_end', 'assigned_to', 'warehouse_id', 'equipment_id', 'project_id'])->all());
            if (array_key_exists('crew', $data)) {
                $this->syncCrew($order, $data['crew'] ?? []);
            }
            if (array_key_exists('tasks', $data)) {
                $this->syncTasks($order, $data['tasks'] ?? []);
            }
            $this->refreshScheduled($order);

            return $order->fresh();
        });
    }

    public function hold(WorkOrder $order): WorkOrder
    {
        if ($order->status !== 'in_progress') {
            throw ValidationException::withMessages(['status' => 'يُعلَّق الأمر الجاري فقط.']);
        }
        foreach ($order->timeEntries()->whereNull('ended_at')->get() as $entry) {
            $this->time->stop($entry);
        }
        $order->update(['status' => 'on_hold']);

        return $order;
    }

    /**
     * Finish the work: no running timer, the required checklist ticked and the sign-off taken when the company asks for them.
     * A linked ticket becomes «resolved».
     */
    public function complete(WorkOrder $order, ?string $notes = null): WorkOrder
    {
        $this->assertOpen($order);
        $rules = $this->settings->all();
        if ($order->timeEntries()->whereNull('ended_at')->exists()) {
            throw ValidationException::withMessages(['timer' => 'أوقف المؤقت الجاري قبل إنهاء الأمر.']);
        }
        if ($rules['require_checklist'] && $order->tasks()->where('is_done', false)->exists()) {
            throw ValidationException::withMessages(['tasks' => 'أكمل بنود قائمة المهام أولًا.']);
        }
        if ($rules['require_signature'] && ! $order->customer_signature_path) {
            throw ValidationException::withMessages(['signature' => 'خُذ توقيع العميل أولًا.']);
        }

        return DB::transaction(function () use ($order, $notes) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $totals = $this->time->totals($order);
            $order->update([
                'status' => 'done', 'completed_at' => now(), 'started_at' => $order->started_at ?? now(),
                'completion_notes' => $notes ?? $order->completion_notes,
                'labour_amount' => $this->labourAmount($order, $totals['billable_minutes']),
                'parts_amount' => $this->parts->amount($order),
                'cost_amount' => round($totals['cost'] + $this->parts->cost($order), 2),
            ]);
            if ($order->ticket_id && ($ticket = Ticket::find($order->ticket_id)) && $ticket->isActive()) {
                $this->sla->setStatus($ticket, 'resolved');
            }

            return $order;
        });
    }

    public function reopen(WorkOrder $order): WorkOrder
    {
        if ($order->status !== 'done') {
            throw ValidationException::withMessages(['status' => 'يُعاد فتح الأمر المنجز غير المفوتر فقط.']);
        }
        $order->update(['status' => 'in_progress', 'completed_at' => null]);

        return $order;
    }

    /** Cancel an order that is not invoiced: running timers stop and the parts go back to stock. */
    public function cancel(WorkOrder $order, ?string $reason = null): WorkOrder
    {
        if (in_array($order->status, ['invoiced', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'لا يمكن إلغاء هذا الأمر.']);
        }

        return DB::transaction(function () use ($order, $reason) {
            foreach ($order->timeEntries()->whereNull('ended_at')->get() as $entry) {
                $this->time->stop($entry);
            }
            // The parts go back to stock even when the order was done: it is no longer billed.
            foreach ($order->parts()->where('returned', false)->get() as $part) {
                $this->parts->giveBack($part, true);
            }
            $order->update(['status' => 'cancelled', 'cancel_reason' => $reason]);

            return $order;
        });
    }

    /** Store the customer's drawn signature (a PNG data URL) as a private file. */
    public function sign(WorkOrder $order, string $name, string $dataUrl): WorkOrder
    {
        $this->assertOpen($order);
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) || strlen($m[1]) > 700_000) {
            throw ValidationException::withMessages(['signature' => 'التوقيع غير صالح.']);
        }
        $binary = base64_decode($m[1], true);
        if ($binary === false || ! str_starts_with($binary, "\x89PNG")) {
            throw ValidationException::withMessages(['signature' => 'التوقيع غير صالح.']);
        }
        $tenant = app(TenantManager::class)->getTenantId();
        $path = "companies/{$tenant}/work-orders/".Str::uuid().'.png';
        Storage::disk('local')->put($path, $binary);
        if ($order->customer_signature_path) {
            Storage::disk('local')->delete($order->customer_signature_path);
        }
        $order->update(['customer_signature_path' => $path, 'signed_by' => $name, 'signed_at' => now()]);

        return $order;
    }

    public function labourAmount(WorkOrder $order, int $billableMinutes): float
    {
        $type = $order->workType;
        if (! $type || $billableMinutes <= 0) {
            return 0.0;
        }

        return round(max($billableMinutes, (int) $type->min_billable_minutes) / 60 * (float) $type->hourly_rate, 2);
    }

    /** The reminder tick: a scheduled order whose start passed without anyone starting it; forgotten timers. */
    public function tick(): array
    {
        $done = ['late' => 0, 'timers' => $this->time->closeStale($this->settings->all()['stale_timer_hours'])];
        WorkOrder::with('customer:id,name')->whereIn('status', ['new', 'scheduled'])->whereNotNull('scheduled_start')->where('scheduled_start', '<', now()->subMinutes(30))->get()
            ->each(function (WorkOrder $o) use (&$done) {
                $state = $o->reminder_state ?? [];
                if (! empty($state['late'])) {
                    return;
                }
                $o->update(['reminder_state' => $state + ['late' => true]]);
                $url = route('work-orders.show', $o->id, false);
                User::where('company_id', $o->company_id)->where('status', 'active')->get()->filter(fn (User $u) => $u->can('work_orders.orders.view'))
                    ->each(fn (User $u) => $u->notify(new ErpAlert('work_order_late', 'أمر عمل تأخر بدؤه', "{$o->number} — {$o->customer->name} — ".$o->scheduled_start->format('Y-m-d H:i'), $url, 'warning')));
                $done['late']++;
            });

        return $done;
    }

    protected function syncCrew(WorkOrder $order, array $ids): void
    {
        $ids = collect($ids)->map(fn ($i) => (int) $i)->filter()->unique()->values();
        $valid = Employee::whereIn('id', $ids)->pluck('id');
        $order->crew()->sync($valid->all());
    }

    protected function syncTasks(WorkOrder $order, array $titles): void
    {
        $keep = [];
        foreach (array_values(array_filter(array_map(fn ($t) => trim((string) (is_array($t) ? ($t['title'] ?? '') : $t)), $titles))) as $i => $title) {
            $existing = $order->tasks()->where('title', $title)->whereNotIn('id', $keep)->first();
            $keep[] = ($existing ?? $order->tasks()->create(['title' => $title, 'position' => $i]))->id;
            $existing?->update(['position' => $i]);
        }
        $order->tasks()->whereNotIn('id', $keep)->delete();
    }

    /** A new order with a lead and a time is «scheduled». */
    protected function refreshScheduled(WorkOrder $order): void
    {
        if ($order->status === 'new' && $order->assigned_to && $order->scheduled_start) {
            $order->update(['status' => 'scheduled']);
        } elseif ($order->status === 'scheduled' && (! $order->assigned_to || ! $order->scheduled_start)) {
            $order->update(['status' => 'new']);
        }
    }

    protected function assertOpen(WorkOrder $order): void
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['status' => 'أمر العمل مغلق ولا يمكن تغييره.']);
        }
    }
}

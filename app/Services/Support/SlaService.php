<?php

namespace App\Services\Support;

use App\Models\PortalUser;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\ErpAlert;
use App\Notifications\PortalTicketReply;
use App\Services\Documents\DocumentNumberService;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Tickets and their service-level clock: deadlines come from the priority's SLA
 * policy, the first public reply stops the response clock, resolving stops the
 * resolution clock, and a nightly/15-minute check flags and announces breaches.
 */
class SlaService
{
    public function __construct(protected DocumentNumberService $numbers) {}

    /** @return array{first_response_minutes: int, resolution_minutes: int} */
    public function policy(string $priority, ?int $companyId = null): array
    {
        $row = SlaPolicy::withoutGlobalScopes()->where('company_id', $companyId ?? app(TenantManager::class)->getTenantId())->where('priority', $priority)->first();
        [$first, $resolve] = SlaPolicy::DEFAULTS[$priority] ?? SlaPolicy::DEFAULTS['normal'];

        return ['first_response_minutes' => (int) ($row->first_response_minutes ?? $first), 'resolution_minutes' => (int) ($row->resolution_minutes ?? $resolve)];
    }

    public function open(array $data): Ticket
    {
        return DB::transaction(function () use ($data) {
            $priority = $data['priority'] ?? 'normal';
            $now = now();
            $sla = $this->policy($priority);

            $ticket = Ticket::create(array_merge($data, [
                'number' => $this->numbers->next('ticket', $now),
                'priority' => $priority, 'status' => 'open',
                'first_response_due_at' => $now->copy()->addMinutes($sla['first_response_minutes']),
                'resolution_due_at' => $now->copy()->addMinutes($sla['resolution_minutes']),
                'created_by' => auth()->id(),
            ]));
            if ($ticket->assigned_to) {
                $this->assigned($ticket);
            } elseif (! auth()->id()) { // opened by the customer from the portal
                $this->alertTeam($ticket, 'ticket_new', 'تذكرة جديدة من العميل: '.$ticket->number, $ticket->subject.($ticket->customer ? ' — '.$ticket->customer->name : ''));
            }

            return $ticket;
        });
    }

    /** A reply on the ticket: «public» (staff to customer), «internal» (note) or «customer» (his answer). */
    public function reply(Ticket $ticket, ?User $user, string $body, string $type, ?int $portalUserId = null): TicketMessage
    {
        if ($ticket->status === 'closed') {
            throw ValidationException::withMessages(['body' => 'التذكرة مغلقة؛ أعد فتحها أولًا.']);
        }

        return DB::transaction(function () use ($ticket, $user, $body, $type, $portalUserId) {
            $message = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $type === 'customer' ? null : $user?->id, 'portal_user_id' => $type === 'customer' ? $portalUserId : null, 'type' => $type, 'body' => $body]);

            if ($type === 'public') {
                if (! $ticket->first_response_at) {
                    $ticket->first_response_at = now();
                    $ticket->response_breached = $ticket->first_response_due_at && now()->gt($ticket->first_response_due_at);
                }
                if ($ticket->status === 'open') {
                    $ticket->status = 'pending'; // the ball is with the customer
                    $this->pause($ticket);
                }
            } elseif ($type === 'customer' && in_array($ticket->status, ['pending', 'resolved'], true)) {
                $this->resume($ticket);
                $ticket->status = 'open';
                $ticket->resolved_at = null;
            }
            $ticket->save();
            if ($type === 'customer') {
                $this->alertTeam($ticket, 'ticket_reply', 'رد العميل على التذكرة '.$ticket->number, mb_substr($body, 0, 140));
            }

            if ($type === 'public') {
                $this->tellCustomer($ticket);
            }

            return $message;
        });
    }

    /** A staff reply e-mails the portal users of the ticket's customer, so they know to come back. */
    protected function tellCustomer(Ticket $ticket): void
    {
        if (! $ticket->customer_id) {
            return;
        }
        $url = route('customer-portal.tickets.show', ['company' => $ticket->company_id, 'ticket' => $ticket->id]);
        PortalUser::where('customer_id', $ticket->customer_id)->where('status', 'active')->get()->each(function (PortalUser $u) use ($ticket, $url) {
            try {
                $u->notify(new PortalTicketReply($ticket->number, $ticket->subject, $url));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function setStatus(Ticket $ticket, string $status): void
    {
        if (! isset(Ticket::STATUSES[$status])) {
            throw ValidationException::withMessages(['status' => 'حالة غير صالحة.']);
        }
        if ($status === $ticket->status) {
            return;
        }

        if ($ticket->status === 'pending') {
            $this->resume($ticket);
        }
        $ticket->status = $status;
        if ($status === 'pending') {
            $this->pause($ticket);
        }
        if (in_array($status, ['resolved', 'closed'], true)) {
            $ticket->resolved_at ??= now();
            $ticket->resolution_breached = $ticket->resolution_due_at && $ticket->resolved_at->gt($ticket->resolution_due_at);
            // Answered by resolving it: the response clock stops as well.
            if (! $ticket->first_response_at) {
                $ticket->first_response_at = now();
                $ticket->response_breached = $ticket->first_response_due_at && now()->gt($ticket->first_response_due_at);
            }
        }
        if ($status === 'closed') {
            $ticket->closed_at = now();
        } else {
            $ticket->closed_at = null;
            if (in_array($status, ['open', 'pending'], true)) { // reopened
                $ticket->resolved_at = null;
                $ticket->resolution_breached = $ticket->resolution_due_at && now()->gt($ticket->resolution_due_at);
            }
        }
        $ticket->save();
    }

    /** A new priority moves the deadlines that are still running (counted from the ticket's creation). */
    public function setPriority(Ticket $ticket, string $priority): void
    {
        if ($priority === $ticket->priority) {
            return;
        }
        $sla = $this->policy($priority, $ticket->company_id);
        $ticket->priority = $priority;
        if (! $ticket->first_response_at) {
            $ticket->first_response_due_at = $ticket->created_at->copy()->addMinutes($sla['first_response_minutes']);
        }
        if ($ticket->isActive()) {
            $ticket->resolution_due_at = $ticket->created_at->copy()->addMinutes($sla['resolution_minutes']);
        }
        $ticket->save();
    }

    /**
     * Flag tickets that missed a deadline and tell the assignee (or the whole support team when unassigned).
     *
     * @return int tickets newly flagged
     */
    public function checkBreaches(?Carbon $now = null): int
    {
        $now ??= now();
        $flagged = 0;

        Ticket::whereIn('status', ['open', 'pending'])->where(function ($q) use ($now) {
            $q->where(fn ($w) => $w->whereNull('first_response_at')->where('response_breached', false)->where('first_response_due_at', '<', $now))
                ->orWhere(fn ($w) => $w->where('status', 'open')->where('resolution_breached', false)->where('resolution_due_at', '<', $now)); // waiting for the customer: clock stopped
        })->with(['customer:id,name', 'assignee:id'])->lazyById(200)->each(function (Ticket $t) use ($now, &$flagged) {
            $kinds = [];
            if (! $t->first_response_at && ! $t->response_breached && $t->first_response_due_at && $t->first_response_due_at->lt($now)) {
                $t->response_breached = true;
                $kinds[] = 'الرد الأول';
            }
            if ($t->status === 'open' && ! $t->resolution_breached && $t->resolution_due_at && $t->resolution_due_at->lt($now)) {
                $t->resolution_breached = true;
                $kinds[] = 'الحل';
            }
            if (! $kinds) {
                return;
            }
            $t->save();
            $flagged++;

            $title = 'تجاوز مهلة '.implode(' و', $kinds).': '.$t->number;
            $body = $t->subject.($t->customer ? ' — '.$t->customer->name : '');
            $this->alertTeam($t, 'ticket_sla_breach', $title, $body, 'danger');
        });

        return $flagged;
    }

    /** The ticket waits for the customer: its resolution clock stops. */
    protected function pause(Ticket $ticket): void
    {
        $ticket->sla_paused_at ??= now();
    }

    /** Back with the team: the deadline moves on by the time it waited. */
    protected function resume(Ticket $ticket): void
    {
        if ($ticket->sla_paused_at && $ticket->resolution_due_at && ! $ticket->resolution_breached) {
            $ticket->resolution_due_at = $ticket->resolution_due_at->copy()->addSeconds((int) $ticket->sla_paused_at->diffInSeconds(now()));
        }
        $ticket->sla_paused_at = null;
    }

    /** Tell the one the ticket was just assigned to. */
    public function assigned(Ticket $ticket): void
    {
        $user = $ticket->assigned_to ? User::find($ticket->assigned_to) : null;
        if ($user && $user->status === 'active' && $user->id !== auth()->id()) { // not when taking it oneself
            $user->notify(new ErpAlert('ticket_assigned', 'أُسندت إليك التذكرة '.$ticket->number, $ticket->subject, route('support.tickets.show', $ticket->id, false), 'info'));
        }
    }

    /** The assignee, or (unassigned) everyone who may work the ticket's branch. */
    protected function alertTeam(Ticket $ticket, string $kind, string $title, string $body, string $level = 'info'): void
    {
        $assignee = $ticket->assigned_to ? User::where('status', 'active')->find($ticket->assigned_to) : null;
        $recipients = $assignee ? collect([$assignee]) : $this->agents($ticket->company_id, $ticket->branch_id);
        $recipients->each(fn (User $u) => $u->notify(new ErpAlert($kind, $title, $body, route('support.tickets.show', $ticket->id, false), $level)));
    }

    /** Active staff who may work tickets (of that branch). */
    public function agents(int $companyId, ?int $branchId = null)
    {
        return User::where('company_id', $companyId)->where('status', 'active')->where('is_super_admin', false)->orderBy('name')->get()
            ->filter(fn (User $u) => ($u->isCompanyOwner() || $u->can('support.tickets.edit')) && ($branchId === null || $u->canUseBranch($branchId)))->values();
    }

    /** Support numbers for the dashboard strip. */
    public function metrics(): array
    {
        $active = Ticket::whereIn('status', ['open', 'pending']);
        $done = Ticket::whereIn('status', ['resolved', 'closed'])->where('resolved_at', '>=', now()->subDays(90));
        $resolved = (clone $done)->get(['created_at', 'resolved_at', 'resolution_breached', 'rating']);

        return [
            'open' => (clone $active)->count(),
            'unassigned' => (clone $active)->whereNull('assigned_to')->count(),
            'overdue' => (clone $active)->where(fn ($q) => $q->where('resolution_due_at', '<', now())->orWhere(fn ($w) => $w->whereNull('first_response_at')->where('first_response_due_at', '<', now())))->count(),
            'avg_resolution_hours' => $resolved->isEmpty() ? null : round($resolved->avg(fn ($t) => $t->created_at->diffInMinutes($t->resolved_at)) / 60, 1),
            'sla_compliance' => $resolved->isEmpty() ? null : round(100 * $resolved->where('resolution_breached', false)->count() / $resolved->count()),
            'avg_rating' => ($rated = $resolved->whereNotNull('rating'))->isEmpty() ? null : round($rated->avg('rating'), 1),
        ];
    }

    /** No-login link the customer opens to rate the support he got. */
    public function surveyLink(Ticket $ticket): string
    {
        return URL::temporarySignedRoute('share.ticket-survey', now()->addDays(config('documents.share_link_days')), ['company' => $ticket->company_id, 'ticket' => $ticket->id]);
    }
}

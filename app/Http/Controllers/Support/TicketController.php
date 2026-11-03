<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\CustomerSubscription;
use App\Models\SupportCategory;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\SlaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Support tickets: list with filters and KPIs, open, conversation, status / priority / assignee. */
class TicketController extends Controller
{
    public function __construct(protected SlaService $sla) {}

    public function index(Request $request): Response
    {
        $f = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|string|max:10', 'priority' => 'nullable|string|max:10', 'assignee' => 'nullable|string|max:10', 'overdue' => 'nullable|boolean']);
        $status = $f['status'] ?? 'active';

        $tickets = Ticket::with(['customer:id,name', 'assignee:id,name', 'category:id,name'])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', ['open', 'pending']))
            ->when($status !== 'active' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($f['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when(($f['assignee'] ?? null) === 'me', fn ($q) => $q->where('assigned_to', auth()->id()))
            ->when(($f['assignee'] ?? null) === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when(($f['assignee'] ?? null) && ! in_array($f['assignee'], ['me', 'none'], true), fn ($q) => $q->where('assigned_to', (int) $f['assignee']))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', ['open', 'pending'])->where(fn ($w) => $w->where('resolution_due_at', '<', now())
                ->orWhere(fn ($x) => $x->whereNull('first_response_at')->where('first_response_due_at', '<', now()))))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('subject', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")->orderBy('resolution_due_at')
            ->paginate($this->perPage($request))->withQueryString();
        $tickets->getCollection()->each(fn ($t) => $t->setAttribute('overdue', $t->isOverdue()));

        return Inertia::render('Support/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => ['search' => $f['search'] ?? '', 'status' => $status, 'priority' => $f['priority'] ?? '', 'assignee' => $f['assignee'] ?? '', 'overdue' => $request->boolean('overdue')],
            'metrics' => $this->sla->metrics(),
            'agents' => $this->agents(),
            'statuses' => Ticket::STATUSES, 'priorities' => Ticket::PRIORITIES,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Support/Tickets/Form', [
            'categories' => SupportCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'agents' => $this->agents(),
            'channels' => Ticket::CHANNELS, 'priorities' => Ticket::PRIORITIES,
            'customerId' => $request->integer('customer') ?: null,
            'subscriptionId' => $request->integer('subscription') ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|tenant_exists:customers,id',
            'contact_name' => 'nullable|string|max:255', 'contact_phone' => 'nullable|string|max:40', 'contact_email' => 'nullable|email|max:255',
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string|max:10000',
            'channel' => 'required|in:phone,email,whatsapp,web,visit,other',
            'priority' => 'required|in:low,normal,high,urgent',
            'category_id' => 'nullable|tenant_exists:support_categories,id',
            'assigned_to' => 'nullable|integer',
            'subscription_id' => 'nullable|tenant_exists:customer_subscriptions,id',
        ], [], ['subject' => 'الموضوع', 'channel' => 'القناة', 'priority' => 'الأولوية']);

        if (empty($data['customer_id']) && empty($data['contact_name'])) {
            return back()->withErrors(['customer_id' => 'اختر العميل أو اكتب اسم المتصل.'])->withInput();
        }
        $data['assigned_to'] = $this->agentId($data['assigned_to'] ?? null);

        $ticket = $this->sla->open($data);

        return redirect()->route('support.tickets.show', $ticket->id)->with('success', 'تم فتح التذكرة '.$ticket->number);
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load(['customer:id,name,code,phone,email', 'assignee:id,name', 'category:id,name', 'messages.user:id,name']);
        $subscription = $ticket->subscription_id ? CustomerSubscription::find($ticket->subscription_id, ['id', 'number']) : null;

        return Inertia::render('Support/Tickets/Show', [
            'ticket' => $ticket,
            'subscription' => $subscription,
            'overdue' => $ticket->isOverdue(),
            'agents' => $this->agents(),
            'categories' => SupportCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'surveyLink' => $ticket->isActive() ? null : $this->sla->surveyLink($ticket),
            'statuses' => Ticket::STATUSES, 'priorities' => Ticket::PRIORITIES, 'channels' => Ticket::CHANNELS,
        ]);
    }

    /** Status, priority, assignee and category in one call (the ticket's side panel). */
    public function update(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'status' => 'nullable|in:open,pending,resolved,closed',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'assigned_to' => 'nullable|integer',
            'category_id' => 'nullable|tenant_exists:support_categories,id',
            'unassign' => 'nullable|boolean',
        ]);

        if ($request->has('assigned_to') || ! empty($data['unassign'])) {
            $ticket->assigned_to = ! empty($data['unassign']) ? null : $this->agentId($data['assigned_to'] ?? null);
        }
        if (array_key_exists('category_id', $data)) {
            $ticket->category_id = $data['category_id'];
        }
        $ticket->save();
        if (! empty($data['priority'])) {
            $this->sla->setPriority($ticket, $data['priority']);
        }
        if (! empty($data['status'])) {
            $this->sla->setStatus($ticket->fresh(), $data['status']);
        }

        return back()->with('success', 'تم تحديث التذكرة');
    }

    public function reply(Request $request, Ticket $ticket)
    {
        $data = $request->validate(['body' => 'required|string|max:10000', 'type' => 'required|in:public,internal,customer'], [], ['body' => 'نص الرد']);
        $this->sla->reply($ticket, $request->user(), $data['body'], $data['type']);

        return back()->with('success', $data['type'] === 'internal' ? 'أُضيفت الملاحظة الداخلية' : 'تم إرسال الرد');
    }

    /** Users the ticket can be assigned to: active staff of the company. */
    protected function agents()
    {
        return User::where('company_id', auth()->user()->company_id)->where('status', 'active')->where('is_super_admin', false)->orderBy('name')->limit(300)->get(['id', 'name']);
    }

    protected function agentId(?int $id): ?int
    {
        return $id && User::where('company_id', auth()->user()->company_id)->whereKey($id)->exists() ? $id : null;
    }
}

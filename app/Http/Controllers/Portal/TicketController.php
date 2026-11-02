<?php

namespace App\Http\Controllers\Portal;

use App\Models\PortalUser;
use App\Models\SupportCategory;
use App\Models\Ticket;
use App\Services\Support\SlaService;
use Illuminate\Http\Request;

/** The customer's tickets: open, follow (public messages only), reply and rate. */
class TicketController extends PortalController
{
    public function __construct(protected SlaService $sla) {}

    public function index(Request $request)
    {
        $this->needs($request, 'support');
        $tickets = $this->scope()->tickets()->latest('id')->paginate(20, ['id', 'number', 'subject', 'status', 'priority', 'created_at', 'rating'])->withQueryString();

        return $this->page($request, 'Portal/Tickets', ['tickets' => $tickets, 'statuses' => Ticket::STATUSES]);
    }

    public function create(Request $request)
    {
        $this->needs($request, 'support');

        return $this->page($request, 'Portal/TicketForm', ['categories' => SupportCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']), 'subscriptionId' => $request->integer('subscription') ?: null]);
    }

    public function store(Request $request, int $company)
    {
        $this->needs($request, 'support');
        $data = $request->validate([
            'subject' => 'required|string|max:255', 'description' => 'required|string|max:10000',
            'category_id' => 'nullable|tenant_exists:support_categories,id', 'subscription_id' => 'nullable|integer',
        ], [], ['subject' => 'الموضوع', 'description' => 'التفاصيل']);

        $scope = $this->scope();
        $subscriptionId = ! empty($data['subscription_id']) ? $scope->subscriptions()->find($data['subscription_id'])?->id : null;
        $ticket = $this->sla->open([
            'customer_id' => $scope->user->customer_id, 'portal_user_id' => $scope->user->id, 'contact_name' => $scope->user->name, 'contact_email' => $scope->user->email,
            'subject' => $data['subject'], 'description' => $data['description'], 'channel' => 'web', 'priority' => 'normal',
            'category_id' => $data['category_id'] ?? null, 'subscription_id' => $subscriptionId,
        ]);

        return redirect()->route('customer-portal.tickets.show', ['company' => $company, 'ticket' => $ticket->id])->with('success', 'وصلتنا تذكرتك '.$ticket->number);
    }

    public function show(Request $request, int $company, int $ticket)
    {
        $this->needs($request, 'support');
        $ticket = $this->scope()->tickets()->findOrFail($ticket);
        $names = PortalUser::whereIn('id', $ticket->messages()->whereNotNull('portal_user_id')->pluck('portal_user_id'))->pluck('name', 'id');

        return $this->page($request, 'Portal/TicketShow', [
            'ticket' => $ticket->only(['id', 'number', 'subject', 'description', 'status', 'priority', 'created_at', 'resolved_at', 'rating', 'rating_comment']),
            // Internal notes never reach the customer; staff names are not shown either.
            'messages' => $ticket->messages()->where('type', '!=', 'internal')->get(['id', 'type', 'body', 'portal_user_id', 'created_at'])
                ->map(fn ($m) => ['id' => $m->id, 'mine' => $m->type === 'customer', 'author' => $m->type === 'customer' ? ($names[$m->portal_user_id] ?? 'أنت') : 'فريق الدعم', 'body' => $m->body, 'created_at' => $m->created_at])->values(),
            'canReply' => $ticket->status !== 'closed',
            'canRate' => ! $ticket->isActive(),
            'statuses' => Ticket::STATUSES,
        ]);
    }

    public function reply(Request $request, int $company, int $ticket)
    {
        $this->needs($request, 'support');
        $ticket = $this->scope()->tickets()->findOrFail($ticket);
        $data = $request->validate(['body' => 'required|string|max:10000'], [], ['body' => 'الرد']);
        $this->sla->reply($ticket, null, $data['body'], 'customer', auth('portal')->id());

        return back()->with('success', 'أُرسل ردك');
    }

    public function rate(Request $request, int $company, int $ticket)
    {
        $this->needs($request, 'support');
        $ticket = $this->scope()->tickets()->findOrFail($ticket);
        abort_if($ticket->isActive(), 404);
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:2000']);
        $ticket->update(['rating' => $data['rating'], 'rating_comment' => $data['comment'] ?? null]);

        return back()->with('success', 'شكرًا لتقييمك');
    }
}

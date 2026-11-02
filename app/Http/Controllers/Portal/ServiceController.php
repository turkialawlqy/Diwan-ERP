<?php

namespace App\Http\Controllers\Portal;

use App\Models\CustomerSubscription;
use App\Models\ProgressBilling;
use App\Models\SupportCategory;
use App\Services\Documents\DocumentSharing;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Support\SlaService;
use Illuminate\Http\Request;

/** The customer's subscriptions (with change / cancel requests) and projects with their progress billings. */
class ServiceController extends PortalController
{
    public function subscriptions(Request $request)
    {
        $subs = $this->scope()->subscriptions()->with(['plan:id,name,cycle', 'recurring:id,next_run_date'])->latest('id')
            ->paginate(20, ['id', 'number', 'service_plan_id', 'recurring_invoice_id', 'quantity', 'unit_price', 'tax_rate', 'status', 'start_date', 'cancel_at_period_end'])->withQueryString();

        return $this->page($request, 'Portal/Subscriptions', ['subscriptions' => $subs, 'statuses' => CustomerSubscription::STATUSES]);
    }

    public function subscription(Request $request, int $company, int $subscription)
    {
        $sub = $this->scope()->subscriptions()->with(['plan:id,name,cycle', 'recurring:id,next_run_date'])->findOrFail($subscription);

        return $this->page($request, 'Portal/SubscriptionShow', [
            'subscription' => $sub->only(['id', 'number', 'quantity', 'unit_price', 'tax_rate', 'status', 'start_date', 'cancel_at_period_end']) + ['plan' => $sub->plan?->only(['name', 'cycle']), 'renews_on' => $sub->recurring?->next_run_date?->toDateString(), 'total' => $sub->periodTotal()],
            'invoices' => app(SubscriptionService::class)->invoices($sub),
            'statuses' => CustomerSubscription::STATUSES,
            'canRequest' => $this->has($request, 'support') && in_array($sub->status, ['trial', 'active', 'past_due', 'paused'], true),
        ]);
    }

    /** A cancel / change request becomes a ticket linked to the subscription; staff decide. Nothing changes by itself. */
    public function requestChange(Request $request, int $company, int $subscription)
    {
        $this->needs($request, 'support');
        $sub = $this->scope()->subscriptions()->findOrFail($subscription);
        $data = $request->validate(['type' => 'required|in:cancel,change', 'note' => 'nullable|string|max:2000']);
        $user = auth('portal')->user();
        $what = $data['type'] === 'cancel' ? 'إلغاء الاشتراك' : 'تغيير خطة الاشتراك';

        $ticket = app(SlaService::class)->open([
            'customer_id' => $user->customer_id, 'portal_user_id' => $user->id, 'contact_name' => $user->name, 'contact_email' => $user->email,
            'subject' => "طلب {$what} — {$sub->number}", 'description' => trim(($data['note'] ?? '')."\n\nطلب من بوابة العملاء بخصوص الاشتراك {$sub->number}."),
            'channel' => 'web', 'priority' => 'normal', 'subscription_id' => $sub->id,
            'category_id' => SupportCategory::firstOrCreate(['name' => 'طلبات الاشتراكات'], ['is_active' => true])->id,
        ]);

        return redirect()->route('customer-portal.tickets.show', ['company' => $company, 'ticket' => $ticket->id])->with('success', 'وصل طلبك للفريق برقم '.$ticket->number);
    }

    public function projects(Request $request)
    {
        $this->needs($request, 'projects');
        $projects = $this->scope()->projects()->latest('id')->paginate(20, ['id', 'code', 'name', 'status', 'start_date', 'end_date', 'location'])->withQueryString();
        $billed = ProgressBilling::where('status', 'approved')->whereIn('project_id', $projects->pluck('id'))->selectRaw('project_id, MAX(cumulative_value) as cumulative, COUNT(*) as n')->groupBy('project_id')->get()->keyBy('project_id');
        $projects->getCollection()->each(fn ($p) => $p->setAttribute('billed', (float) ($billed[$p->id]->cumulative ?? 0)) && $p->setAttribute('billings', (int) ($billed[$p->id]->n ?? 0)));

        return $this->page($request, 'Portal/Projects', ['projects' => $projects]);
    }

    public function project(Request $request, int $company, int $project)
    {
        $this->needs($request, 'projects');
        $project = $this->scope()->projects()->findOrFail($project);
        $sharing = app(DocumentSharing::class);
        $billings = $this->scope()->billings()->where('project_id', $project->id)->orderBy('sequence')->get(['id', 'number', 'sequence', 'billing_date', 'period_from', 'period_to', 'cumulative_value', 'current_value', 'net_payable'])
            ->map(fn ($b) => $b->toArray() + ['pdf_url' => $sharing->pdfLink($b)]);

        return $this->page($request, 'Portal/ProjectShow', [
            'project' => $project->only(['id', 'code', 'name', 'status', 'start_date', 'end_date', 'location', 'description', 'contract_number']),
            'billings' => $billings,
        ]);
    }
}

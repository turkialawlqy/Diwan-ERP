<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\CustomerSubscription;
use App\Models\ServicePlan;
use App\Rules\AllowedBranch;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Customer subscriptions: list, start, and the lifecycle actions (pause, resume, change plan, cancel). */
class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|string|max:20', 'plan' => 'nullable|integer']);

        $subscriptions = CustomerSubscription::with(['customer:id,name,code', 'plan:id,name,cycle', 'recurring:id,next_run_date,status'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['plan'] ?? null, fn ($q, $p) => $q->where('service_plan_id', $p))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderByRaw("CASE status WHEN 'past_due' THEN 0 WHEN 'trial' THEN 1 WHEN 'active' THEN 2 WHEN 'paused' THEN 3 ELSE 4 END")->latest('id')
            ->paginate($this->perPage($request))->withQueryString();

        $live = CustomerSubscription::whereIn('status', ['active', 'past_due', 'trial'])->count();
        $renewals = CustomerSubscription::with(['customer:id,name', 'recurring:id,next_run_date'])->whereIn('status', ['active', 'past_due'])
            ->whereHas('recurring', fn ($q) => $q->whereBetween('next_run_date', [today(), today()->addDays(30)]))->get()
            ->sortBy(fn ($s) => $s->recurring->next_run_date)->take(8)->values()
            ->map(fn ($s) => ['id' => $s->id, 'number' => $s->number, 'customer' => $s->customer?->name, 'date' => $s->recurring->next_run_date->toDateString(), 'amount' => $s->periodTotal(), 'ends' => $s->cancel_at_period_end || ! $s->auto_renew]);

        return Inertia::render('Sales/Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $filters['status'] ?? '', 'plan' => $filters['plan'] ?? ''],
            'stats' => [
                'mrr' => $this->service->mrr(), 'live' => $live,
                'past_due' => CustomerSubscription::where('status', 'past_due')->count(),
                'cancelled_30' => CustomerSubscription::whereIn('status', ['cancelled', 'expired'])->where('cancelled_at', '>=', today()->subDays(30))->count(),
            ],
            'renewals' => $renewals,
            'plans' => ServicePlan::orderBy('name')->get(['id', 'name']),
            'statuses' => CustomerSubscription::STATUSES,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Sales/Subscriptions/Form', [
            'plans' => ServicePlan::where('is_active', true)->orderBy('name')->get(['id', 'name', 'price', 'tax_rate', 'cycle', 'interval', 'trial_days']),
            'cycles' => ServicePlan::CYCLES,
            'customerId' => $request->integer('customer') ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'service_plan_id' => 'required|tenant_exists:service_plans,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'quantity' => 'required|numeric|gt:0',
            'unit_price' => 'nullable|numeric|min:0',
            'start_date' => 'required|date',
            'auto_renew' => 'boolean',
            'auto_send' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ], [], ['customer_id' => 'العميل', 'service_plan_id' => 'الخطة', 'start_date' => 'تاريخ البدء']);

        $subscription = $this->service->start($data);

        return redirect()->route('sales.subscriptions.show', $subscription->id)->with('success', 'تم بدء الاشتراك '.$subscription->number);
    }

    public function show(CustomerSubscription $subscription): Response
    {
        $subscription->load(['customer:id,name,code,email,phone', 'plan', 'pendingPlan:id,name,price', 'recurring']);

        return Inertia::render('Sales/Subscriptions/Show', [
            'subscription' => $subscription,
            'renewsOn' => $this->service->renewsOn($subscription)?->toDateString(),
            'periodTotal' => $subscription->periodTotal(),
            'invoices' => $this->service->invoices($subscription),
            'plans' => ServicePlan::where('is_active', true)->where('id', '!=', $subscription->service_plan_id)->orderBy('name')->get(['id', 'name', 'price', 'cycle']),
            'statuses' => CustomerSubscription::STATUSES,
            'cycles' => ServicePlan::CYCLES,
        ]);
    }

    public function pause(CustomerSubscription $subscription)
    {
        $this->service->pause($subscription);

        return back()->with('success', 'تم إيقاف الاشتراك؛ لن تصدر فواتير حتى استئنافه');
    }

    public function resume(CustomerSubscription $subscription)
    {
        $this->service->resume($subscription);

        return back()->with('success', 'تم استئناف الاشتراك');
    }

    public function changePlan(Request $request, CustomerSubscription $subscription)
    {
        $data = $request->validate(['service_plan_id' => 'required|tenant_exists:service_plans,id', 'when' => 'required|in:now,renewal']);
        $plan = ServicePlan::findOrFail($data['service_plan_id']);
        $invoice = $this->service->changePlan($subscription, $plan, $data['when']);

        return back()->with('success', $invoice
            ? "تمت الترقية وصدرت فاتورة الفرق {$invoice->invoice_number}"
            : ($subscription->fresh()->pending_plan_id ? 'سيُطبَّق تغيير الخطة عند التجديد القادم' : 'تم تغيير الخطة'));
    }

    public function cancel(Request $request, CustomerSubscription $subscription)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500', 'at_period_end' => 'boolean']);
        $this->service->cancel($subscription, $data['reason'] ?? null, (bool) ($data['at_period_end'] ?? true));

        return back()->with('success', ($data['at_period_end'] ?? true) ? 'سيُلغى الاشتراك في نهاية الفترة الحالية' : 'تم إلغاء الاشتراك');
    }

    public function keep(CustomerSubscription $subscription)
    {
        $this->service->keep($subscription);

        return back()->with('success', 'تم التراجع عن الإلغاء وسيستمر الاشتراك');
    }

    /** A subscription that never billed anything can be removed. */
    public function destroy(CustomerSubscription $subscription)
    {
        if ($subscription->recurring && $subscription->recurring->generated_count > 0) {
            throw ValidationException::withMessages(['subscription' => 'صدرت له فواتير فلا يُحذف؛ ألغِه بدل الحذف.']);
        }
        $subscription->recurring?->delete();
        $subscription->delete();

        return redirect()->route('sales.subscriptions.index')->with('success', 'تم حذف الاشتراك');
    }
}

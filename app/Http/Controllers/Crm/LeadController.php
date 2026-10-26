<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Light CRM: leads through a pipeline, follow-up activities, conversion to customer.
 */
class LeadController extends Controller
{
    public const STATUSES = ['new' => 'جديد', 'contacted' => 'تم التواصل', 'qualified' => 'مؤهل', 'proposal' => 'عرض سعر', 'won' => 'تم الكسب', 'lost' => 'خسارة'];

    public const SOURCES = ['website' => 'الموقع', 'referral' => 'توصية', 'call' => 'اتصال', 'exhibition' => 'معرض', 'social' => 'تواصل اجتماعي', 'other' => 'أخرى'];

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'assigned_to' => 'nullable|integer']);
        $leads = Lead::with('assignee:id,name')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            ->where(fn ($q) => $q->whereNotIn('status', ['won', 'lost'])->orWhere('updated_at', '>=', now()->subDays(60)))
            ->orderBy('next_follow_up')->limit(500)->get();

        return Inertia::render('Crm/Leads', [
            'board' => collect(self::STATUSES)->map(fn ($label, $status) => ['status' => $status, 'label' => $label, 'leads' => $leads->where('status', $status)->values(),
                'value' => round((float) $leads->where('status', $status)->sum('expected_value'), 2)])->values(),
            'users' => $this->users(),
            'sources' => self::SOURCES,
            'filters' => $filters,
            'stats' => [
                'open_value' => (float) Lead::whereNotIn('status', ['won', 'lost'])->sum('expected_value'),
                'won_this_month' => Lead::where('status', 'won')->where('updated_at', '>=', now()->startOfMonth())->count(),
                'due_follow_ups' => Lead::whereNotIn('status', ['won', 'lost'])->whereDate('next_follow_up', '<=', now())->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $lead = Lead::create($this->validated($request) + ['status' => 'new']);

        return redirect()->route('crm.leads.show', $lead->id)->with('success', 'تم تسجيل العميل المحتمل');
    }

    public function show(Lead $lead)
    {
        return Inertia::render('Crm/Lead', [
            'lead' => $lead->load(['assignee:id,name', 'customer:id,name', 'activities' => fn ($q) => $q->with('user:id,name')->latest('happened_at')]),
            'users' => $this->users(),
            'statuses' => self::STATUSES,
            'sources' => self::SOURCES,
        ]);
    }

    public function update(Request $request, Lead $lead)
    {
        $validated = $this->validated($request) + $request->validate([
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'lost_reason' => 'nullable|required_if:status,lost|string|max:500',
        ]);
        $lead->update($validated);

        return back()->with('success', 'تم التحديث');
    }

    public function move(Request $request, Lead $lead)
    {
        $lead->update($request->validate(['status' => ['required', Rule::in(array_keys(self::STATUSES))]]));

        return back();
    }

    public function storeActivity(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'type' => 'required|in:call,meeting,email,whatsapp,note',
            'notes' => 'required|string|max:5000',
            'happened_at' => 'nullable|date',
            'next_follow_up' => 'nullable|date',
        ]);

        DB::transaction(function () use ($lead, $validated) {
            $lead->activities()->create(['type' => $validated['type'], 'notes' => $validated['notes'], 'happened_at' => $validated['happened_at'] ?? now(), 'user_id' => auth()->id()]);
            $lead->update(['next_follow_up' => $validated['next_follow_up'] ?? $lead->next_follow_up, 'status' => $lead->status === 'new' ? 'contacted' : $lead->status]);
        });

        return back()->with('success', 'تم تسجيل النشاط');
    }

    /**
     * Turn a won lead into a customer (once).
     */
    public function convert(Lead $lead)
    {
        if ($lead->customer_id) {
            return redirect()->route('sales.clients.show', $lead->customer_id);
        }

        $customer = DB::transaction(function () use ($lead) {
            $customer = Customer::create([
                'code' => $this->nextNumber('customer'),
                'name' => $lead->name,
                'company_name' => $lead->company_name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'customer_type' => $lead->company_name ? 'commercial' : 'individual',
                'status' => 'active',
                'notes' => 'محول من العملاء المحتملين',
            ]);
            $lead->update(['customer_id' => $customer->id, 'status' => 'won']);

            return $customer;
        });

        return redirect()->route('sales.clients.show', $customer->id)->with('success', 'تم تحويل العميل المحتمل إلى عميل');
    }

    protected function validated(Request $request): array
    {
        $companyId = app(TenantManager::class)->getTenantId();

        return $request->validate([
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'source' => ['nullable', Rule::in(array_keys(self::SOURCES))],
            'expected_value' => 'nullable|numeric|min:0',
            'next_follow_up' => 'nullable|date',
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('company_id', $companyId)],
            'notes' => 'nullable|string|max:5000',
        ]);
    }

    protected function users()
    {
        return User::where('company_id', app(TenantManager::class)->getTenantId())->where('status', 'active')->orderBy('name')->get(['id', 'name']);
    }
}

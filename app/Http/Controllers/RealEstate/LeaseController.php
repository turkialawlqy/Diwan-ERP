<?php

namespace App\Http\Controllers\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use App\Models\Lease;
use App\Models\PropertyUnit;
use App\Models\WorkOrder;
use App\Services\Sectors\LeaseService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Leases: a contract + a rent subscription + the unit going occupied (see LeaseService). */
class LeaseController extends Controller
{
    public function __construct(protected LeaseService $leases) {}

    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => 'nullable|in:active,ended', 'search' => 'nullable|string|max:100']);
        $leases = Lease::with(['unit.property:id,name', 'customer:id,name'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))->orWhereHas('unit', fn ($u) => $u->where('unit_number', 'like', "%{$s}%"))))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('RealEstate/Leases/Index', ['leases' => $leases, 'filters' => ['status' => $f['status'] ?? '', 'search' => $f['search'] ?? ''], 'statuses' => Lease::STATUSES, 'cycles' => Lease::CYCLES]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('RealEstate/Leases/Form', [
            'rentalUnits' => PropertyUnit::with('property:id,name')->where('status', 'vacant')->orderBy('property_id')->orderBy('unit_number')->get()->map(fn ($u) => ['id' => $u->id, 'label' => $u->label(), 'rent_amount' => (float) $u->rent_amount]),
            'templates' => ContractTemplate::where('kind', 'lease')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'unitId' => $request->integer('unit') ?: null, 'cycles' => Lease::CYCLES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id', 'property_unit_id' => 'required|tenant_exists:property_units,id', 'start_date' => 'required|date', 'end_date' => 'required|date',
            'rent_amount' => 'required|numeric|min:0.01', 'cycle' => 'required|in:monthly,quarterly,yearly', 'security_deposit' => 'nullable|numeric|min:0', 'residential' => 'boolean', 'template_id' => 'nullable|tenant_exists:contract_templates,id',
        ], [], ['customer_id' => 'المستأجر', 'property_unit_id' => 'الوحدة', 'rent_amount' => 'قيمة الإيجار']);
        $lease = $this->leases->create($data);

        return redirect()->route('real-estate.leases.show', $lease->id)->with('success', 'أُبرم عقد الإيجار '.$lease->number);
    }

    public function show(Lease $lease): Response
    {
        $lease->load(['unit.property:id,name', 'customer:id,name,phone', 'contract:id,number,status', 'subscription:id,number,status,recurring_invoice_id']);

        return Inertia::render('RealEstate/Leases/Show', [
            'lease' => $lease->toArray() + ['unit_label' => $lease->unit->label()],
            'invoices' => $this->leases->invoices($lease),
            'requests' => WorkOrder::where('property_unit_id', $lease->property_unit_id)->latest('id')->limit(10)->get(['id', 'number', 'title', 'status']),
            'statuses' => Lease::STATUSES, 'cycles' => Lease::CYCLES,
        ]);
    }

    public function end(Request $request, Lease $lease)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $this->leases->end($lease, $data['reason'] ?? null);

        return back()->with('success', 'أُنهي العقد وأُخليت الوحدة');
    }

    /** Leases expiring within 60 days and leases whose rent is overdue. */
    public function report(): Response
    {
        $active = Lease::with(['unit.property:id,name', 'customer:id,name', 'subscription:id,status'])->where('status', 'active');
        $leases = $active->get();

        return Inertia::render('RealEstate/Report', [
            'expiring' => $leases->filter(fn ($l) => $l->end_date->lte(today()->addDays(60)))->sortBy('end_date')->map(fn ($l) => ['id' => $l->id, 'number' => $l->number, 'unit' => $l->unit->label(), 'tenant' => $l->customer->name, 'end_date' => $l->end_date->toDateString(), 'days' => today()->diffInDays($l->end_date, false)])->values(),
            'arrears' => $leases->filter(fn ($l) => $l->subscription?->status === 'past_due')->map(fn ($l) => ['id' => $l->id, 'number' => $l->number, 'unit' => $l->unit->label(), 'tenant' => $l->customer->name, 'rent' => (float) $l->rent_amount])->values(),
            'occupancy' => ['units' => PropertyUnit::count(), 'occupied' => PropertyUnit::where('status', 'occupied')->count(), 'vacant' => PropertyUnit::where('status', 'vacant')->count()],
        ]);
    }
}

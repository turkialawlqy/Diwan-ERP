<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Equipment;
use App\Models\EquipmentMaintenanceSchedule;
use App\Models\FixedAsset;
use App\Models\Warehouse;
use App\Rules\AllowedBranch;
use App\Services\Equipment\EquipmentReports;
use App\Services\Equipment\MaintenanceService;
use App\Services\Equipment\MaintenanceTracker;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EquipmentController extends Controller
{
    public function __construct(protected MaintenanceTracker $tracker) {}

    public function index(Request $request)
    {
        $equipment = Equipment::with(['schedules', 'supplier:id,name', 'activeRental.customer:id,name', 'activeRental.project:id,code,name'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('code', 'like', $request->q.'%')->orWhere('plate_number', 'like', '%'.$request->q.'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->orderBy('code')
            ->paginate($this->perPage($request));

        $equipment->getCollection()->each(function (Equipment $e) {
            $worst = $e->schedules->where('is_active', true)->map(fn ($s) => ['name' => $s->name] + $this->tracker->status($s, $e))
                ->sortByDesc(fn ($s) => [MaintenanceTracker::LEVELS[$s['state']], $s['percent']])->first();
            $e->setAttribute('next_maintenance', $worst ? ['name' => $worst['name'], 'state' => $worst['state'], 'percent' => $worst['percent']] : null);
            $e->unsetRelation('schedules');
        });

        return Inertia::render('Equipment/Index', [
            'equipment' => $equipment,
            'filters' => $request->only('q', 'status', 'category'),
            'categories' => Equipment::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'counts' => Equipment::groupBy('status')->selectRaw('status, COUNT(*) c')->pluck('c', 'status'),
            'statuses' => Equipment::STATUSES,
            'lookups' => $this->lookups(),
        ]);
    }

    public function store(Request $request)
    {
        $equipment = Equipment::create($this->validated($request) + ['status' => 'available']);

        return redirect()->route('equipment.show', $equipment)->with('success', 'تمت إضافة المعدة. أضف بنود الصيانة الدورية لها من تبويب «جدول الصيانة».');
    }

    public function show(Equipment $equipment, EquipmentReports $reports)
    {
        $equipment->load(['supplier:id,name', 'fixedAsset:id,code,name_ar', 'costCenter:id,code,name_ar', 'schedules.product:id,name_ar,sku']);
        $schedules = $equipment->schedules->sortBy('name')->values()->map(fn ($s) => $s->toArray() + ['status' => $this->tracker->status($s, $equipment)]);

        $from = now()->subMonths(11)->startOfMonth();
        $profit = collect($reports->profitability($from, now()->endOfDay())['rows'])->firstWhere('id', $equipment->id);

        return Inertia::render('Equipment/Show', [
            'equipment' => $equipment->setRelation('schedules', collect()),
            'schedules' => $schedules,
            'readings' => $equipment->readings()->with('rental:id,number')->latest('date')->limit(60)->get(),
            'rentals' => $equipment->rentals()->with(['customer:id,name', 'project:id,code,name'])->latest('start_date')->get(),
            'orders' => $equipment->maintenanceOrders()->withCount('items')->latest('date')->limit(50)->get(),
            'billings' => $equipment->billings()->with(['invoice:id,invoice_number', 'rental:id,number,type'])->latest('period_to')->limit(50)->get(),
            'profit' => $profit,
            'profitFrom' => $from->toDateString(),
            'others' => Equipment::whereKeyNot($equipment->id)->whereHas('schedules')->orderBy('code')->get(['id', 'code', 'name', 'model']),
            'statuses' => Equipment::STATUSES,
            'lookups' => $this->lookups(),
        ]);
    }

    public function update(Request $request, Equipment $equipment)
    {
        $data = $this->validated($request, $equipment);
        $outOfService = $request->boolean('out_of_service');
        $equipment->update($data + ['status' => $outOfService ? 'out_of_service' : ($equipment->status === 'out_of_service' ? 'available' : $equipment->status)]);
        $equipment->syncStatus();

        return back()->with('success', 'تم تحديث بيانات المعدة');
    }

    public function destroy(Equipment $equipment)
    {
        if ($equipment->billings()->exists() || $equipment->maintenanceOrders()->exists() || $equipment->rentals()->exists()) {
            return back()->with('error', 'لا يمكن حذف معدة لها عقود أو فواتير أو أوامر صيانة؛ اجعلها «خارج الخدمة» بدلاً من ذلك.');
        }
        $equipment->delete();

        return redirect()->route('equipment.index')->with('success', 'تم حذف المعدة');
    }

    public function storeSchedule(Request $request, Equipment $equipment)
    {
        $data = $this->scheduleData($request);
        // Blank "last done" = starts counting from today's meters.
        $equipment->schedules()->create(array_merge($data, [
            'last_done_date' => $data['last_done_date'] ?? now()->toDateString(),
            'last_done_hours' => $data['last_done_hours'] ?? $equipment->current_hours,
            'last_done_km' => $data['last_done_km'] ?? $equipment->current_km,
        ]));
        $this->tracker->refresh($equipment->fresh());

        return back()->with('success', 'تمت إضافة بند الصيانة الدورية');
    }

    public function updateSchedule(Request $request, EquipmentMaintenanceSchedule $schedule)
    {
        $schedule->update($this->scheduleData($request));
        $schedule->forceFill(['alert_state' => 'ok'])->save();
        $this->tracker->refresh($schedule->equipment->fresh());

        return back()->with('success', 'تم تحديث بند الصيانة');
    }

    public function destroySchedule(EquipmentMaintenanceSchedule $schedule)
    {
        $schedule->delete();

        return back()->with('success', 'تم حذف بند الصيانة');
    }

    public function copySchedules(Request $request, Equipment $equipment, MaintenanceService $service)
    {
        $request->validate(['from_id' => 'required|tenant_exists:equipment,id']);
        $count = $service->copySchedules(Equipment::findOrFail($request->integer('from_id')), $equipment);

        return back()->with('success', "تم نسخ {$count} بند صيانة؛ بدأ احتسابها من القراءة الحالية للمعدة.");
    }

    protected function validated(Request $request, ?Equipment $equipment = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('equipment', 'code')->where('company_id', app(TenantManager::class)->getTenantId())->ignore($equipment?->id)->whereNull('deleted_at')],
            'name' => 'required|string|max:191',
            'category' => 'nullable|string|max:60',
            'model' => 'nullable|string|max:100',
            'plate_number' => 'nullable|string|max:30',
            'serial_number' => 'nullable|string|max:60',
            'ownership' => 'required|in:owned,rented_in',
            'fixed_asset_id' => 'nullable|tenant_exists:fixed_assets,id',
            'supplier_id' => 'nullable|required_if:ownership,rented_in|tenant_exists:suppliers,id',
            'supplier_rate' => 'nullable|required_if:ownership,rented_in|numeric|min:0',
            'supplier_rate_basis' => 'nullable|required_if:ownership,rented_in|in:hourly,daily,monthly',
            'meter_type' => 'required|in:hours,km,both,none',
            'current_hours' => 'nullable|numeric|min:0',
            'current_km' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'daily_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'internal_hourly_cost' => 'nullable|numeric|min:0',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'notes' => 'nullable|string|max:2000',
        ], [
            'code.unique' => 'رمز المعدة مستخدم لمعدة أخرى.',
            'supplier_id.required_if' => 'حدد المورد المؤجّر للمعدة.',
            'supplier_rate.required_if' => 'أدخل سعر الإيجار من المورد.',
            'supplier_rate_basis.required_if' => 'حدد أساس سعر الإيجار (ساعة/يوم/شهر).',
        ], ['code' => 'رمز المعدة', 'name' => 'اسم المعدة']);

        foreach (['supplier_rate', 'current_hours', 'current_km', 'hourly_rate', 'daily_rate', 'monthly_rate', 'internal_hourly_cost'] as $key) {
            $data[$key] = (float) ($data[$key] ?? 0);
        }
        if ($data['ownership'] === 'owned') {
            $data['supplier_id'] = null;
            $data['supplier_rate'] = 0;
            $data['supplier_rate_basis'] = null;
        }
        // Meters move only through readings once the equipment has any.
        if ($equipment && $equipment->readings()->exists()) {
            unset($data['current_hours'], $data['current_km']);
        }

        return $data;
    }

    protected function scheduleData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'product_id' => 'nullable|tenant_exists:products,id',
            'default_quantity' => 'nullable|numeric|min:0',
            'interval_hours' => 'nullable|numeric|min:1',
            'interval_km' => 'nullable|numeric|min:1',
            'interval_days' => 'nullable|integer|min:1',
            'warn_hours' => 'nullable|numeric|min:0',
            'warn_km' => 'nullable|numeric|min:0',
            'warn_days' => 'nullable|integer|min:0',
            'last_done_date' => 'nullable|date',
            'last_done_hours' => 'nullable|numeric|min:0',
            'last_done_km' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ], [], ['name' => 'اسم البند', 'interval_hours' => 'كل (ساعة)', 'interval_km' => 'كل (كم)', 'interval_days' => 'كل (يوم)']);

        if (empty($data['interval_hours']) && empty($data['interval_km']) && empty($data['interval_days'])) {
            throw ValidationException::withMessages(['interval_hours' => 'حدد فترة واحدة على الأقل: بالساعات أو الكيلومترات أو الأيام.']);
        }
        foreach (['interval_hours', 'interval_km', 'interval_days', 'warn_hours', 'warn_km', 'warn_days', 'last_done_date', 'last_done_hours', 'last_done_km', 'product_id'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === '') {
                $data[$key] = null;
            }
        }
        $data['default_quantity'] = (float) ($data['default_quantity'] ?? 1);

        return $data;
    }

    protected function lookups(): array
    {
        return [
            'fixed_assets' => FixedAsset::orderBy('code')->get(['id', 'code', 'name_ar']),
            'cost_centers' => CostCenter::orderBy('code')->get(['id', 'code', 'name_ar']),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
            'bases' => Equipment::BASES,
        ];
    }
}

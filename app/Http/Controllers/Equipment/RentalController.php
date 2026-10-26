<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentRental;
use App\Models\Project;
use App\Services\Documents\DocumentNumberService;
use App\Services\Equipment\RentalBilling;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RentalController extends Controller
{
    public function index(Request $request)
    {
        $rentals = EquipmentRental::with(['equipment:id,code,name', 'customer:id,name', 'project:id,code,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.$request->q.'%')
                ->orWhereHas('equipment', fn ($e) => $e->where('name', 'like', '%'.$request->q.'%')->orWhere('code', 'like', $request->q.'%'))
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$request->q.'%'))))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")->latest('start_date')
            ->paginate($this->perPage($request));

        return Inertia::render('Equipment/Rentals/Index', [
            'rentals' => $rentals,
            'filters' => $request->only('status', 'type', 'q'),
            'equipment' => Equipment::where('status', '!=', 'out_of_service')->orderBy('code')
                ->get(['id', 'code', 'name', 'status', 'hourly_rate', 'daily_rate', 'monthly_rate', 'internal_hourly_cost']),
            'projects' => Project::whereNotIn('status', ['completed', 'closed', 'cancelled'])->orderBy('code')->get(['id', 'code', 'name']),
            'prefillEquipment' => $request->integer('equipment') ?: null,
        ]);
    }

    public function store(Request $request, DocumentNumberService $numbers)
    {
        $data = $this->validated($request);
        $this->assertFree($data);
        $rental = EquipmentRental::create($data + [
            'number' => $numbers->next('equipment_rental', Carbon::parse($data['start_date'])),
            'status' => 'active',
            'created_by' => auth()->id(),
        ]);
        $rental->equipment->syncStatus();

        return redirect()->route('equipment.rentals.show', $rental)->with('success', "تم إنشاء العقد {$rental->number}");
    }

    public function show(EquipmentRental $rental)
    {
        $rental->load(['equipment', 'customer:id,name,tax_number,email', 'project:id,code,name', 'billings.invoice:id,invoice_number,total,payment_status']);
        $suggestFrom = $rental->billed_until ? $rental->billed_until->copy()->addDay() : $rental->start_date->copy();
        $suggestTo = $suggestFrom->copy()->endOfMonth();
        if ($rental->end_date && $suggestTo->gt($rental->end_date)) {
            $suggestTo = $rental->end_date->copy();
        }

        return Inertia::render('Equipment/Rentals/Show', [
            'rental' => $rental,
            'readings' => $rental->readings()->latest('date')->limit(62)->get(['id', 'date', 'hours_reading', 'km_reading', 'worked_hours', 'operator']),
            'summary' => [
                'worked_hours' => round((float) $rental->readings()->sum('worked_hours'), 1),
                'billed' => round((float) $rental->billings()->sum('amount'), 2),
                'billed_quantity' => round((float) $rental->billings()->sum('quantity'), 2),
            ],
            'suggest' => ['from' => $suggestFrom->toDateString(), 'to' => $suggestTo->toDateString()],
            'bases' => Equipment::BASES,
        ]);
    }

    public function update(Request $request, EquipmentRental $rental)
    {
        $data = $request->validate([
            'rate' => 'required|numeric|min:0',
            'min_hours_per_day' => 'nullable|numeric|min:0|max:24',
            'end_date' => 'nullable|date|after_or_equal:'.$rental->start_date->toDateString(),
            'site' => 'nullable|string|max:191',
            'with_operator' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ]);
        $data['min_hours_per_day'] = ($data['min_hours_per_day'] ?? '') === '' ? null : $data['min_hours_per_day'];
        $rental->update($data);

        return back()->with('success', 'تم تحديث العقد');
    }

    public function close(Request $request, EquipmentRental $rental)
    {
        $data = $request->validate(['end_date' => 'required|date|after_or_equal:'.$rental->start_date->toDateString()]);
        $rental->update(['status' => 'closed', 'end_date' => $data['end_date']]);
        $rental->equipment->syncStatus();

        $unbilled = ! $rental->billed_until || $rental->billed_until->lt($rental->end_date);

        return back()->with($unbilled ? 'error' : 'success', $unbilled
            ? 'تم إنهاء العقد. تنبيه: توجد فترة غير مفوترة حتى '.$rental->end_date->toDateString().'؛ أصدر فاتورتها من هذه الصفحة.'
            : 'تم إنهاء العقد وإعادة المعدة إلى المتاحة');
    }

    public function preview(Request $request, EquipmentRental $rental, RentalBilling $billing)
    {
        $data = $request->validate(['period_from' => 'required|date', 'period_to' => 'required|date|after_or_equal:period_from']);

        return response()->json($billing->preview($rental, Carbon::parse($data['period_from']), Carbon::parse($data['period_to'])));
    }

    public function bill(Request $request, EquipmentRental $rental, RentalBilling $billing)
    {
        $data = $request->validate([
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'quantity' => 'nullable|numeric|min:0',
            'date' => 'nullable|date',
        ], [], ['period_from' => 'بداية الفترة', 'period_to' => 'نهاية الفترة', 'quantity' => 'الكمية']);
        $result = $billing->bill($rental, $data);

        return back()->with('success', $rental->type === 'customer'
            ? "تم إصدار الفاتورة {$result->reference} بمبلغ ".number_format($result->amount + $result->tax_amount, 2)
            : 'تم تحميل '.number_format($result->amount, 2).' على المشروع');
    }

    public function supplierPreview(Request $request, Equipment $equipment, RentalBilling $billing)
    {
        $data = $request->validate(['period_from' => 'required|date', 'period_to' => 'required|date|after_or_equal:period_from', 'tax_rate' => 'nullable|numeric|min:0|max:100']);

        return response()->json($billing->supplierPreview($equipment, Carbon::parse($data['period_from']), Carbon::parse($data['period_to']), (float) ($data['tax_rate'] ?? TaxProfile::rate())));
    }

    public function supplierBill(Request $request, Equipment $equipment, RentalBilling $billing)
    {
        $data = $request->validate([
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'quantity' => 'nullable|numeric|min:0',
            'tax_rate' => ['nullable', 'numeric', TaxProfile::rateRule()],
            'reference' => 'nullable|string|max:60',
            'date' => 'nullable|date',
        ]);
        $result = $billing->supplierBill($equipment, $data);

        return back()->with('success', 'تم تسجيل إيجار المورد بمبلغ '.number_format($result->amount + $result->tax_amount, 2));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => 'required|in:customer,project',
            'equipment_id' => 'required|tenant_exists:equipment,id',
            'customer_id' => 'required_if:type,customer|nullable|tenant_exists:customers,id',
            'project_id' => 'required_if:type,project|nullable|tenant_exists:projects,id',
            'billing_basis' => 'required|in:hourly,daily,monthly',
            'rate' => 'required|numeric|min:0',
            'min_hours_per_day' => 'nullable|numeric|min:0|max:24',
            'with_operator' => 'boolean',
            'tax_rate' => ['nullable', 'numeric', TaxProfile::rateRule()],
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'site' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:2000',
        ], [
            'customer_id.required_if' => 'اختر العميل المستأجر.',
            'project_id.required_if' => 'اختر المشروع.',
        ], ['equipment_id' => 'المعدة', 'rate' => 'السعر', 'start_date' => 'تاريخ البداية', 'end_date' => 'تاريخ النهاية']);

        if ($data['type'] === 'customer') {
            $data['project_id'] = null;
        } else {
            $data['customer_id'] = null;
            $data['tax_rate'] = 0;
        }
        $data['tax_rate'] = (float) ($data['tax_rate'] ?? TaxProfile::rate());
        $data['min_hours_per_day'] = ($data['min_hours_per_day'] ?? '') === '' ? null : $data['min_hours_per_day'];

        return $data;
    }

    /** One active contract at a time per equipment for overlapping dates. */
    protected function assertFree(array $data): void
    {
        $equipment = Equipment::findOrFail($data['equipment_id']);
        if ($equipment->status === 'out_of_service') {
            throw ValidationException::withMessages(['equipment_id' => 'المعدة خارج الخدمة.']);
        }
        $clash = EquipmentRental::where('equipment_id', $equipment->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $data['start_date']))
            ->when($data['end_date'] ?? null, fn ($q, $end) => $q->whereDate('start_date', '<=', $end))
            ->first();
        if ($clash) {
            throw ValidationException::withMessages(['equipment_id' => "المعدة مرتبطة بعقد نشط ({$clash->number}) في نفس الفترة؛ أنهِ العقد أولاً."]);
        }
    }
}

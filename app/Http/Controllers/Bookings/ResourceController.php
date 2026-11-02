<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Models\BookableService;
use App\Models\BookingResource;
use App\Models\Employee;
use App\Models\ResourceHour;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Bookable resources (staff, rooms, equipment, courts) with weekly hours and time off. */
class ResourceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Bookings/Resources/Index', [
            'resources' => BookingResource::withCount(['bookings as upcoming' => fn ($q) => $q->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', now())])->orderBy('name')->get(),
            'types' => BookingResource::TYPES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Bookings/Resources/Form', ['resource' => null] + $this->lookups());
    }

    public function edit(BookingResource $resource): Response
    {
        $resource->load(['hours', 'timeOffs', 'services:id']);

        return Inertia::render('Bookings/Resources/Form', ['resource' => $resource->toArray() + ['service_ids' => $resource->services->pluck('id')->all()]] + $this->lookups());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $resource = BookingResource::create(collect($data)->only(['name', 'type', 'employee_id', 'capacity', 'color', 'is_active', 'notes'])->all());
        $this->sync($resource, $data, $request->has('hours'));

        if ($json = $this->createdResponse($request, $resource, $resource->name)) {
            return $json;
        }

        return redirect()->route('bookings.resources.index')->with('success', 'تمت إضافة المورد');
    }

    public function update(Request $request, BookingResource $resource)
    {
        $data = $this->validated($request);
        $resource->update(collect($data)->only(['name', 'type', 'employee_id', 'capacity', 'color', 'is_active', 'notes'])->all());
        $this->sync($resource, $data, true);

        return redirect()->route('bookings.resources.index')->with('success', 'تم حفظ المورد');
    }

    public function destroy(BookingResource $resource)
    {
        if ($resource->bookings()->exists()) {
            throw ValidationException::withMessages(['resource' => 'عليه حجوزات فلا يُحذف؛ عطّله بدل الحذف.']);
        }
        $resource->delete();

        return back()->with('success', 'تم حذف المورد');
    }

    protected function sync(BookingResource $resource, array $data, bool $replaceChildren): void
    {
        if (! $replaceChildren) {
            return;
        }
        $resource->hours()->delete();
        foreach ($data['hours'] ?? [] as $h) {
            $resource->hours()->create(['weekday' => $h['weekday'], 'start_time' => $h['start_time'], 'end_time' => $h['end_time']]);
        }
        $resource->timeOffs()->delete();
        foreach ($data['time_offs'] ?? [] as $o) {
            $resource->timeOffs()->create(['starts_at' => $o['starts_at'], 'ends_at' => $o['ends_at'], 'reason' => $o['reason'] ?? null]);
        }
        $resource->services()->sync(collect($data['service_ids'] ?? [])->all());
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:staff,room,equipment,court',
            'employee_id' => 'nullable|tenant_exists:employees,id',
            'capacity' => 'nullable|integer|min:1|max:500',
            'color' => 'nullable|string|max:9',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'hours' => 'nullable|array|max:60',
            'hours.*.weekday' => 'required|integer|between:1,7',
            'hours.*.start_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'hours.*.end_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'time_offs' => 'nullable|array|max:100',
            'time_offs.*.starts_at' => 'required|date',
            'time_offs.*.ends_at' => 'required|date',
            'time_offs.*.reason' => 'nullable|string|max:255',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer|tenant_exists:bookable_services,id',
        ], [], ['name' => 'الاسم']);

        foreach ($data['hours'] ?? [] as $h) {
            if ($h['end_time'] <= $h['start_time']) {
                throw ValidationException::withMessages(['hours' => 'نهاية الدوام يجب أن تكون بعد بدايته.']);
            }
        }
        foreach ($data['time_offs'] ?? [] as $o) {
            if (strtotime($o['ends_at']) <= strtotime($o['starts_at'])) {
                throw ValidationException::withMessages(['time_offs' => 'نهاية الإجازة يجب أن تكون بعد بدايتها.']);
            }
        }
        $data['capacity'] = $data['capacity'] ?? 1;
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }

    protected function lookups(): array
    {
        return [
            'types' => BookingResource::TYPES,
            'days' => ResourceHour::DAYS,
            'services' => BookableService::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::orderBy('name')->limit(500)->get(['id', 'name']),
        ];
    }
}

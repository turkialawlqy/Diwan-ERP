<?php

namespace App\Http\Controllers\Workshop;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The customers' vehicles: card, history of work orders and the next service. */
class VehicleController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['search' => 'nullable|string|max:100', 'due' => 'nullable|boolean']);
        $vehicles = CustomerVehicle::with('customer:id,name,phone')
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('plate', 'like', "%{$s}%")->orWhere('make', 'like', "%{$s}%")->orWhere('model', 'like', "%{$s}%")->orWhere('vin', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->when($request->boolean('due'), fn ($q) => $q->whereNotNull('next_service_date')->whereDate('next_service_date', '<=', today()->addDays(30)))
            ->orderBy('next_service_date')->latest('id')->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Workshop/Vehicles/Index', ['vehicles' => $vehicles, 'filters' => ['search' => $f['search'] ?? '', 'due' => $request->boolean('due')]]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Workshop/Vehicles/Form', ['vehicle' => null, 'customer' => $request->filled('customer') ? Customer::find($request->integer('customer')) : null]);
    }

    public function edit(CustomerVehicle $vehicle): Response
    {
        return Inertia::render('Workshop/Vehicles/Form', ['vehicle' => $vehicle->load('customer:id,name'), 'customer' => $vehicle->customer]);
    }

    public function store(Request $request)
    {
        $vehicle = CustomerVehicle::create($this->validated($request));

        if ($json = $this->createdResponse($request, $vehicle, $vehicle->label())) {
            return $json;
        }

        return redirect()->route('workshop.vehicles.show', $vehicle->id)->with('success', 'أُضيفت المركبة '.$vehicle->plate);
    }

    public function update(Request $request, CustomerVehicle $vehicle)
    {
        $vehicle->update($this->validated($request));

        return redirect()->route('workshop.vehicles.show', $vehicle->id)->with('success', 'تم حفظ المركبة');
    }

    public function destroy(CustomerVehicle $vehicle)
    {
        if ($vehicle->orders()->exists()) {
            throw ValidationException::withMessages(['vehicle' => 'عليها أوامر عمل فلا تُحذف.']);
        }
        $vehicle->delete();

        return redirect()->route('workshop.vehicles.index')->with('success', 'تم حذف المركبة');
    }

    public function show(CustomerVehicle $vehicle): Response
    {
        $vehicle->load('customer:id,name,phone');

        return Inertia::render('Workshop/Vehicles/Show', [
            'vehicle' => $vehicle->toArray() + ['label' => $vehicle->label()],
            'orders' => WorkOrder::with('workType:id,name')->where('vehicle_id', $vehicle->id)->latest('id')->limit(50)->get(['id', 'number', 'title', 'work_type_id', 'status', 'odometer_in', 'completed_at', 'created_at']),
            'statuses' => WorkOrder::STATUSES,
        ]);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id', 'plate' => 'required|string|max:30', 'make' => 'nullable|string|max:60', 'model' => 'nullable|string|max:60',
            'year' => 'nullable|integer|between:1950,2100', 'vin' => 'nullable|string|max:40', 'color' => 'nullable|string|max:30', 'odometer' => 'nullable|integer|min:0|max:9999999',
            'next_service_date' => 'nullable|date', 'next_service_km' => 'nullable|integer|min:0|max:9999999', 'notes' => 'nullable|string|max:2000',
        ], [], ['customer_id' => 'العميل', 'plate' => 'رقم اللوحة']) + ['odometer' => 0];
    }
}

<?php

namespace App\Http\Controllers\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyUnit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Properties and their units board (vacant / occupied / under maintenance). */
class PropertyController extends Controller
{
    public function index(): Response
    {
        $props = Property::withCount([
            'units', 'units as vacant_count' => fn ($q) => $q->where('status', 'vacant'), 'units as occupied_count' => fn ($q) => $q->where('status', 'occupied'),
        ])->orderBy('name')->get();

        return Inertia::render('RealEstate/Properties/Index', ['properties' => $props, 'types' => Property::TYPES,
            'totals' => ['units' => PropertyUnit::count(), 'vacant' => PropertyUnit::where('status', 'vacant')->count(), 'occupied' => PropertyUnit::where('status', 'occupied')->count()]]);
    }

    public function create(): Response
    {
        return Inertia::render('RealEstate/Properties/Form', ['property' => null, 'types' => Property::TYPES]);
    }

    public function edit(Property $property): Response
    {
        return Inertia::render('RealEstate/Properties/Form', ['property' => $property, 'types' => Property::TYPES]);
    }

    public function store(Request $request)
    {
        $property = Property::create($this->validated($request));

        if ($json = $this->createdResponse($request, $property, $property->name)) {
            return $json;
        }

        return redirect()->route('real-estate.properties.show', $property->id)->with('success', 'أُضيف العقار');
    }

    public function update(Request $request, Property $property)
    {
        $property->update($this->validated($request));

        return redirect()->route('real-estate.properties.show', $property->id)->with('success', 'تم حفظ العقار');
    }

    public function destroy(Property $property)
    {
        if ($property->units()->exists()) {
            throw ValidationException::withMessages(['property' => 'له وحدات فلا يُحذف.']);
        }
        $property->delete();

        return redirect()->route('real-estate.properties.index')->with('success', 'تم حذف العقار');
    }

    public function show(Property $property): Response
    {
        $units = $property->units()->get()->map(function (PropertyUnit $u) {
            $lease = $u->status === 'occupied' ? Lease::with('customer:id,name')->where('property_unit_id', $u->id)->where('status', 'active')->first() : null;

            return $u->toArray() + ['tenant' => $lease?->customer?->name, 'lease_id' => $lease?->id, 'lease_end' => $lease?->end_date?->toDateString()];
        });

        return Inertia::render('RealEstate/Properties/Show', ['property' => $property, 'units' => $units, 'types' => Property::TYPES, 'unitTypes' => PropertyUnit::TYPES, 'statuses' => PropertyUnit::STATUSES]);
    }

    protected function validated(Request $request): array
    {
        return $request->validate(['name' => 'required|string|max:255', 'type' => 'required|in:building,villa,complex,shop,land', 'address' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000'], [], ['name' => 'الاسم']);
    }
}

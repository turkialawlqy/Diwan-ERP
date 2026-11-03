<?php

namespace App\Http\Controllers\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyUnit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('RealEstate/Units/Form', ['unit' => null, 'propertyId' => $request->integer('property') ?: null] + $this->lookups());
    }

    public function edit(PropertyUnit $unit): Response
    {
        return Inertia::render('RealEstate/Units/Form', ['unit' => $unit, 'propertyId' => $unit->property_id] + $this->lookups());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $unit = PropertyUnit::create($data + ['status' => 'vacant']);

        if ($json = $this->createdResponse($request, $unit, $unit->unit_number)) {
            return $json;
        }

        return redirect()->route('real-estate.properties.show', $unit->property_id)->with('success', 'أُضيفت الوحدة');
    }

    public function update(Request $request, PropertyUnit $unit)
    {
        $data = $this->validated($request, $unit);
        // A rented unit stays rented until its lease ends.
        if ($unit->status === 'occupied') {
            unset($data['status']);
        }
        $unit->update($data);

        return redirect()->route('real-estate.properties.show', $unit->property_id)->with('success', 'تم حفظ الوحدة');
    }

    public function destroy(PropertyUnit $unit)
    {
        if ($unit->leases()->exists()) {
            throw ValidationException::withMessages(['unit' => 'عليها عقود إيجار فلا تُحذف.']);
        }
        $propertyId = $unit->property_id;
        $unit->delete();

        return redirect()->route('real-estate.properties.show', $propertyId)->with('success', 'تم حذف الوحدة');
    }

    protected function lookups(): array
    {
        return ['properties' => Property::orderBy('name')->get(['id', 'name']), 'types' => PropertyUnit::TYPES];
    }

    protected function validated(Request $request, ?PropertyUnit $unit = null): array
    {
        $propertyId = $request->integer('property_id');

        return $request->validate([
            'property_id' => 'required|tenant_exists:properties,id',
            'unit_number' => ['required', 'string', 'max:30', Rule::unique('property_units', 'unit_number')->where('property_id', $propertyId)->ignore($unit?->id)],
            'type' => 'required|in:apartment,shop,office,warehouse,room', 'floor' => 'nullable|string|max:10', 'area' => 'nullable|numeric|min:0', 'rent_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:vacant,maintenance', 'notes' => 'nullable|string|max:2000',
        ], ['unit_number.unique' => 'رقم الوحدة مستعمل في هذا العقار.'], ['unit_number' => 'رقم الوحدة']) + ['rent_amount' => 0];
    }
}

<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentReading;
use App\Services\Equipment\MaintenanceTracker;
use App\Services\Equipment\ReadingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Daily sheet: one row per working equipment, filled by the site supervisor.
 */
class ReadingController extends Controller
{
    public function index(Request $request)
    {
        $date = Carbon::parse($request->input('date', now()->toDateString()))->toDateString();
        $equipment = Equipment::where('status', '!=', 'out_of_service')->where('meter_type', '!=', 'none')
            ->with(['activeRental.customer:id,name', 'activeRental.project:id,code,name'])
            ->orderByRaw("CASE status WHEN 'rented' THEN 0 WHEN 'internal' THEN 1 ELSE 2 END")->orderBy('code')
            ->get(['id', 'code', 'name', 'category', 'meter_type', 'current_hours', 'current_km', 'last_reading_date', 'status']);

        $today = EquipmentReading::whereDate('date', $date)->get()->keyBy('equipment_id');
        $previous = EquipmentReading::whereDate('date', '<', $date)->whereIn('equipment_id', $equipment->pluck('id'))
            ->orderByDesc('date')->get()->unique('equipment_id')->keyBy('equipment_id');

        return Inertia::render('Equipment/Readings', [
            'date' => $date,
            'rows' => $equipment->map(fn ($e) => [
                'equipment' => $e,
                'reading' => $today[$e->id] ?? null,
                'previous' => isset($previous[$e->id]) ? $previous[$e->id]->only(['date', 'hours_reading', 'km_reading']) : null,
            ]),
            'recent' => EquipmentReading::with('equipment:id,code,name')->latest('updated_at')->limit(15)->get(),
        ]);
    }

    public function store(Request $request, ReadingService $service)
    {
        $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'rows' => 'required|array|min:1',
            'rows.*.equipment_id' => 'required|tenant_exists:equipment,id',
            'rows.*.hours_reading' => 'nullable|numeric|min:0',
            'rows.*.km_reading' => 'nullable|numeric|min:0',
            'rows.*.worked_hours' => 'nullable|numeric|min:0|max:24',
            'rows.*.operator' => 'nullable|string|max:100',
            'rows.*.notes' => 'nullable|string|max:500',
        ], [], ['rows.*.worked_hours' => 'ساعات العمل', 'date' => 'التاريخ']);

        $saved = 0;
        $errors = [];
        foreach ($request->input('rows') as $i => $row) {
            if (($row['hours_reading'] ?? '') === '' && ($row['km_reading'] ?? '') === '' && ($row['worked_hours'] ?? '') === '') {
                continue;
            }
            try {
                $service->record(Equipment::findOrFail($row['equipment_id']), $row + ['date' => $request->input('date')]);
                $saved++;
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors["rows.{$i}.{$field}"] = $messages[0];
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors + ['rows' => "حُفظت {$saved} قراءة، وتعذّر حفظ ".count($errors).' (راجع الحقول المظللة).']);
        }

        return back()->with('success', $saved ? "تم حفظ {$saved} قراءة" : 'لم تُدخل أي قراءة');
    }

    public function destroy(EquipmentReading $reading, ReadingService $service, MaintenanceTracker $tracker)
    {
        abort_if($reading->rental_id && $reading->rental?->billed_until && $reading->date->lte($reading->rental->billed_until), 422, 'لا يمكن حذف قراءة ضمن فترة مفوترة.');
        $equipment = $reading->equipment;
        $reading->delete();
        $service->syncMeters($equipment);
        $tracker->refresh($equipment->fresh());

        return back()->with('success', 'تم حذف القراءة');
    }
}

<?php

namespace App\Services\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentReading;
use App\Models\EquipmentRental;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily meter readings (drive maintenance) and worked hours (drive billing).
 */
class ReadingService
{
    public function __construct(protected MaintenanceTracker $tracker) {}

    /**
     * @param  array  $data  date, hours_reading?, km_reading?, worked_hours?, operator?, notes?
     */
    public function record(Equipment $equipment, array $data): EquipmentReading
    {
        $reading = DB::transaction(function () use ($equipment, $data) {
            $equipment = Equipment::lockForUpdate()->findOrFail($equipment->id);
            $date = Carbon::parse($data['date'])->toDateString();
            $hours = $this->number($data['hours_reading'] ?? null);
            $km = $this->number($data['km_reading'] ?? null);

            $before = EquipmentReading::where('equipment_id', $equipment->id)->whereDate('date', '<', $date)->orderByDesc('date')->first();
            $after = EquipmentReading::where('equipment_id', $equipment->id)->whereDate('date', '>', $date)->orderBy('date')->first();
            $existing = EquipmentReading::where('equipment_id', $equipment->id)->whereDate('date', $date)->first();
            // The first reading cannot go below the meter entered with the equipment.
            $floorHours = $before?->hours_reading ?? ($existing ? null : $equipment->current_hours);
            $floorKm = $before?->km_reading ?? ($existing ? null : $equipment->current_km);
            $this->assertOrder('hours_reading', 'الساعات', $hours, $floorHours, $after?->hours_reading);
            $this->assertOrder('km_reading', 'الكيلومترات', $km, $floorKm, $after?->km_reading);

            // Worked hours default to the meter difference for hour meters.
            $worked = $this->number($data['worked_hours'] ?? null);
            if ($worked === null && $hours !== null && $floorHours !== null) {
                $worked = max(0, round($hours - $floorHours, 2));
            }
            if ($worked !== null && $worked > 24) {
                throw ValidationException::withMessages(['worked_hours' => "ساعات العمل المحسوبة من العداد ({$worked}) تتجاوز 24 ساعة؛ أدخل ساعات العمل الفعلية لهذا اليوم."]);
            }

            $rental = EquipmentRental::where('equipment_id', $equipment->id)->where('status', 'active')
                ->whereDate('start_date', '<=', $date)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
                ->latest('id')->first();

            $reading = $existing ?? new EquipmentReading(['equipment_id' => $equipment->id, 'date' => $date]);
            $reading->fill(['rental_id' => $rental?->id, 'hours_reading' => $hours, 'km_reading' => $km, 'worked_hours' => $worked ?? 0,
                'operator' => $data['operator'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => auth()->id()])->save();

            $this->syncMeters($equipment);

            return $reading;
        });

        $this->tracker->refresh($equipment->fresh());

        return $reading;
    }

    /** Meters always reflect the latest reading. */
    public function syncMeters(Equipment $equipment): void
    {
        $latest = fn (string $column) => EquipmentReading::where('equipment_id', $equipment->id)->whereNotNull($column)->orderByDesc('date')->value($column);
        $equipment->forceFill([
            'current_hours' => $latest('hours_reading') ?? $equipment->current_hours,
            'current_km' => $latest('km_reading') ?? $equipment->current_km,
            'last_reading_date' => EquipmentReading::where('equipment_id', $equipment->id)->max('date'),
        ])->save();
    }

    protected function assertOrder(string $field, string $label, ?float $value, ?float $previous, ?float $next): void
    {
        if ($value === null) {
            return;
        }
        if ($previous !== null && $value < $previous) {
            throw ValidationException::withMessages([$field => "قراءة {$label} ({$value}) أقل من القراءة السابقة ({$previous})."]);
        }
        if ($next !== null && $value > $next) {
            throw ValidationException::withMessages([$field => "قراءة {$label} ({$value}) أكبر من قراءة لاحقة مسجلة ({$next})."]);
        }
    }

    protected function number($value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}

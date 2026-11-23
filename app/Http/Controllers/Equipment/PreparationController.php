<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Equipment;
use App\Models\EquipmentPreparation;
use App\Services\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * «تحضير المعدات»: each working day every piece of equipment is prepared (checked) and the preparation cards are attached
 * as one or several files. The index is the day's sheet: who was prepared, who needs attention, who is still missing.
 */
class PreparationController extends Controller
{
    public function index(Request $request)
    {
        $date = Carbon::parse($request->input('date', now()->toDateString()))->toDateString();
        $equipment = Equipment::where('status', '!=', 'out_of_service')->orderBy('code')->get(['id', 'code', 'name', 'category', 'status']);
        $done = EquipmentPreparation::whereDate('prep_date', $date)->get()->keyBy('equipment_id');
        $files = Attachment::where('attachable_type', (new EquipmentPreparation)->getMorphClass())->whereIn('attachable_id', $done->pluck('id'))
            ->selectRaw('attachable_id, count(*) as n')->groupBy('attachable_id')->pluck('n', 'attachable_id');

        $rows = $equipment->map(fn ($e) => [
            'equipment' => $e,
            'preparation' => ($p = $done[$e->id] ?? null) ? $p->only(['id', 'number', 'status', 'operator', 'notes', 'prep_date']) + ['files' => (int) ($files[$p->id] ?? 0)] : null,
        ])->values();

        return Inertia::render('Equipment/Preparations/Index', [
            'date' => $date,
            'rows' => $rows,
            'counts' => [
                'total' => $rows->count(),
                'ready' => $rows->where('preparation.status', 'ready')->count(),
                'needs_attention' => $rows->where('preparation.status', 'needs_attention')->count(),
                'missing' => $rows->whereNull('preparation')->count(),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $date = Carbon::parse($request->input('date', now()->toDateString()))->toDateString();

        return Inertia::render('Equipment/Preparations/Form', [
            'preparation' => null,
            'equipment' => $this->equipmentList(),
            'defaults' => ['equipment_id' => $request->integer('equipment_id') ?: '', 'prep_date' => $date],
            'suggestedNumber' => $this->peekNumber('equipment_preparation', $date),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->assertFirstOfTheDay($data['equipment_id'], $data['prep_date']);

        $preparation = DB::transaction(function () use ($data, $request) {
            $preparation = EquipmentPreparation::create($data + ['number' => $this->nextNumber('equipment_preparation', $data['prep_date']), 'created_by' => auth()->id()]);
            app(AttachmentService::class)->claim($request, $preparation, 'equipment-preparation');

            return $preparation;
        });

        return redirect()->route('equipment.preparations.index', ['date' => $preparation->prep_date->toDateString()])->with('success', "تم تسجيل تحضير المعدة {$preparation->number}");
    }

    /**
     * Prepares a group of equipment for one day in a single request. Needs the same permission as a single preparation
     * (equipment.preparations.create); equipment already prepared that day or out of service is skipped, never overwritten.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'prep_date' => 'required|date|before_or_equal:today',
            'status' => 'required|in:ready,needs_attention',
            'operator' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:2000',
            'equipment_ids' => 'required|array|min:1|max:500',
            'equipment_ids.*' => 'required|tenant_exists:equipment,id',
        ]);
        $date = Carbon::parse($data['prep_date'])->toDateString();

        [$created, $skipped] = DB::transaction(function () use ($data, $date) {
            $ids = collect($data['equipment_ids'])->map(fn ($i) => (int) $i)->unique()->values();
            $active = Equipment::whereIn('id', $ids)->where('status', '!=', 'out_of_service')->pluck('id');
            $done = EquipmentPreparation::whereIn('equipment_id', $ids)->whereDate('prep_date', $date)->pluck('equipment_id');
            $todo = $active->diff($done)->values();
            foreach ($todo as $id) {
                EquipmentPreparation::create([
                    'equipment_id' => $id,
                    'prep_date' => $date,
                    'status' => $data['status'],
                    'operator' => $data['operator'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'number' => $this->nextNumber('equipment_preparation', $date),
                    'created_by' => auth()->id(),
                ]);
            }

            return [$todo->count(), $ids->count() - $todo->count()];
        });

        $message = "تم تحضير {$created} معدة دفعة واحدة";
        if ($skipped > 0) {
            $message .= " وتخطي {$skipped} (محضَّرة في هذا اليوم أو خارج الخدمة)";
        }

        return redirect()->route('equipment.preparations.index', ['date' => $date])->with('success', $message);
    }

    public function edit(EquipmentPreparation $preparation)
    {
        return Inertia::render('Equipment/Preparations/Form', [
            'preparation' => $preparation->only(['id', 'number', 'equipment_id', 'operator', 'hours_reading', 'km_reading', 'status', 'notes']) + ['prep_date' => $preparation->prep_date->toDateString()],
            'equipment' => $this->equipmentList(),
            'defaults' => null,
            'suggestedNumber' => $preparation->number,
        ]);
    }

    public function update(Request $request, EquipmentPreparation $preparation)
    {
        $data = $this->validated($request);
        if ((int) $data['equipment_id'] !== (int) $preparation->equipment_id || $data['prep_date'] !== $preparation->prep_date->toDateString()) {
            $this->assertFirstOfTheDay($data['equipment_id'], $data['prep_date'], $preparation->id);
        }
        $preparation->update($data);

        return redirect()->route('equipment.preparations.index', ['date' => $preparation->prep_date->toDateString()])->with('success', 'تم تحديث التحضير');
    }

    public function destroy(EquipmentPreparation $preparation)
    {
        $date = $preparation->prep_date->toDateString();
        DB::transaction(function () use ($preparation) {
            $preparation->attachments->each(function (Attachment $a) {
                Storage::disk('local')->delete($a->path);
                $a->delete();
            });
            $preparation->delete();
        });

        return redirect()->route('equipment.preparations.index', ['date' => $date])->with('success', 'تم حذف التحضير');
    }

    protected function equipmentList()
    {
        return Equipment::where('status', '!=', 'out_of_service')->orderBy('code')->get(['id', 'code', 'name', 'meter_type']);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'equipment_id' => 'required|tenant_exists:equipment,id',
            'prep_date' => 'required|date|before_or_equal:today',
            'operator' => 'nullable|string|max:120',
            'hours_reading' => 'nullable|numeric|min:0',
            'km_reading' => 'nullable|numeric|min:0',
            'status' => 'required|in:ready,needs_attention',
            'notes' => 'nullable|string|max:2000',
        ]);
    }

    protected function assertFirstOfTheDay(int|string $equipmentId, string $date, ?int $except = null): void
    {
        $exists = EquipmentPreparation::where('equipment_id', $equipmentId)->whereDate('prep_date', $date)->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['equipment_id' => 'هذه المعدة محضَّرة في هذا اليوم؛ عدّل تحضيرها بدل إضافة آخر.']);
        }
    }
}

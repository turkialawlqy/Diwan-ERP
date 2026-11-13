<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\ProcessCostPeriod;
use App\Models\ProcessStage;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WorkCenter;
use App\Support\PickerOptions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProcessStageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Manufacturing/Process/Stages/Index', ['stages' => ProcessStage::with(['product:id,name_ar', 'previous:id,name', 'warehouse:id,name_ar'])->orderBy('sequence')->get()]);
    }

    public function create(): Response
    {
        return $this->form(new ProcessStage(['sequence' => (int) ProcessStage::max('sequence') + 1, 'is_active' => true]));
    }

    public function edit(ProcessStage $stage): Response
    {
        return $this->form($stage);
    }

    protected function form(ProcessStage $stage): Response
    {
        return Inertia::render('Manufacturing/Process/Stages/Form', [
            'stage' => $stage,
            'products' => PickerOptions::from(Product::where('is_active', true)->where('type', '!=', 'service')->orderBy('name_ar'), ['id', 'name_ar', 'sku'], [$stage->product_id]),
            'stages' => ProcessStage::when($stage->id, fn ($q) => $q->whereKeyNot($stage->id))->orderBy('sequence')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderByDesc('is_main')->get(['id', 'name_ar']),
            'workCenters' => WorkCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        ProcessStage::create($this->validated($request));

        return redirect()->route('manufacturing.stages.index')->with('success', 'تمت إضافة المرحلة.');
    }

    public function update(Request $request, ProcessStage $stage)
    {
        $stage->update($this->validated($request, $stage));

        return redirect()->route('manufacturing.stages.index')->with('success', 'تم تحديث المرحلة.');
    }

    public function destroy(ProcessStage $stage)
    {
        if (ProcessCostPeriod::where('process_stage_id', $stage->id)->exists() || ProcessStage::where('previous_stage_id', $stage->id)->exists()) {
            $stage->update(['is_active' => false]);

            return back()->with('success', 'للمرحلة فترات أو مراحل تابعة فأُوقفت ولم تُحذف.');
        }
        $stage->delete();

        return back()->with('success', 'تم حذف المرحلة.');
    }

    protected function validated(Request $request, ?ProcessStage $stage = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('process_stages', 'code')->where('company_id', $request->user()->company_id)->ignore($stage?->id)],
            'name' => 'required|string|max:255',
            'sequence' => 'required|integer|min:1|max:999',
            'product_id' => 'required|tenant_exists:products,id',
            'previous_stage_id' => 'nullable|tenant_exists:process_stages,id',
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            'work_center_id' => 'nullable|tenant_exists:work_centers,id',
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}

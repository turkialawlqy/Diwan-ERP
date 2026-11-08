<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\ProcessCostPeriod;
use App\Models\ProcessStage;
use App\Models\Product;
use App\Models\WorkCenter;
use App\Services\Manufacturing\ProcessCostingService;
use App\Support\PickerOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcessPeriodController extends Controller
{
    public function __construct(protected ProcessCostingService $costing) {}

    public function index(): Response
    {
        return Inertia::render('Manufacturing/Process/Periods/Index', ['periods' => ProcessCostPeriod::with('stage:id,code,name')->orderByDesc('date_from')->orderByDesc('id')->paginate(20)]);
    }

    public function create(): Response
    {
        return Inertia::render('Manufacturing/Process/Periods/Create', ['stages' => ProcessStage::where('is_active', true)->orderBy('sequence')->get(['id', 'code', 'name'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['process_stage_id' => 'required|tenant_exists:process_stages,id', 'date_from' => 'required|date', 'date_to' => 'required|date|after_or_equal:date_from']);
        $period = $this->costing->openPeriod(ProcessStage::findOrFail($data['process_stage_id']), $data['date_from'], $data['date_to']);

        return redirect()->route('manufacturing.periods.show', $period)->with('success', 'فُتحت الفترة.');
    }

    public function show(ProcessCostPeriod $period): Response
    {
        $period->load('stage.product:id,name_ar', 'stage.warehouse:id,name_ar');

        return Inertia::render('Manufacturing/Process/Periods/Show', [
            'period' => $period,
            'products' => PickerOptions::from(Product::where('is_active', true)->where('type', '!=', 'service')->orderBy('name_ar'), ['id', 'name_ar', 'sku']),
            'workCenters' => WorkCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'isFinal' => $period->stage->isFinal(),
        ]);
    }

    public function issue(Request $request, ProcessCostPeriod $period)
    {
        $data = $request->validate(['lines' => 'required|array|min:1|max:50', 'lines.*.product_id' => 'required|tenant_exists:products,id', 'lines.*.quantity' => 'required|numeric|min:0.0001']);
        $cost = $this->costing->issueMaterials($period, $data['lines']);

        return back()->with('success', 'تم صرف المواد بتكلفة '.number_format($cost, 2));
    }

    public function conversion(Request $request, ProcessCostPeriod $period)
    {
        $data = $request->validate(['work_center_id' => 'required|tenant_exists:work_centers,id', 'minutes' => 'required|numeric|min:0.01']);
        $cost = $this->costing->addConversion($period, (int) $data['work_center_id'], (float) $data['minutes']);

        return back()->with('success', 'أُضيفت تكلفة التحويل '.number_format($cost, 2));
    }

    public function close(Request $request, ProcessCostPeriod $period)
    {
        $data = $request->validate([
            'units_started' => 'required|numeric|min:0', 'units_completed' => 'required|numeric|min:0', 'units_closing_wip' => 'required|numeric|min:0',
            'closing_material_pct' => 'required|numeric|min:0|max:100', 'closing_conversion_pct' => 'required|numeric|min:0|max:100',
        ]);
        $this->costing->close($period, $data);

        return redirect()->route('manufacturing.periods.show', $period)->with('success', 'أُغلقت الفترة وحُسبت تكلفة الوحدة.');
    }
}

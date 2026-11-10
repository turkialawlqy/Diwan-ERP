<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\QcInspection;
use App\Models\QcPlan;
use App\Services\Documents\DocumentNumberService;
use App\Services\Manufacturing\QualityService;
use App\Support\PickerOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QcInspectionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['result' => 'nullable|in:pending,pass,fail,partial']);

        return Inertia::render('Manufacturing/Quality/Inspections/Index', [
            'inspections' => QcInspection::with('product:id,name_ar')->when($filters['result'] ?? null, fn ($q, $v) => $q->where('result', $v))->orderByDesc('id')->paginate($this->perPage($request, 15))->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Manufacturing/Quality/Inspections/Create', [
            'products' => PickerOptions::from(Product::where('is_active', true)->orderBy('name_ar'), ['id', 'name_ar', 'sku']),
            'plans' => QcPlan::where('is_active', true)->get(['id', 'name', 'product_id', 'applies_to']),
        ]);
    }

    public function store(Request $request, DocumentNumberService $numbers)
    {
        $data = $request->validate(['product_id' => 'required|tenant_exists:products,id', 'qc_plan_id' => 'nullable|tenant_exists:qc_plans,id', 'inspected_quantity' => 'required|numeric|min:0.0001', 'notes' => 'nullable|string|max:1000']);
        $inspection = QcInspection::create($data + [
            'inspection_number' => $numbers->next('qc_inspection', now()), 'inspection_date' => now()->toDateString(),
            'accepted_quantity' => 0, 'rejected_quantity' => 0, 'rework_quantity' => 0, 'result' => 'pending', 'disposition' => 'accept', 'inspector_id' => auth()->id(),
        ]);

        return redirect()->route('manufacturing.inspections.show', $inspection)->with('success', 'فُتح الفحص.');
    }

    public function show(QcInspection $inspection): Response
    {
        $inspection->load(['plan', 'product:id,name_ar,sku']);

        return Inertia::render('Manufacturing/Quality/Inspections/Show', ['inspection' => $inspection, 'source' => $inspection->source_type ? class_basename($inspection->source_type) : null]);
    }

    public function complete(Request $request, QcInspection $inspection, QualityService $quality)
    {
        $data = $request->validate([
            'accepted' => 'required|numeric|min:0', 'rejected' => 'required|numeric|min:0', 'rework' => 'nullable|numeric|min:0',
            'disposition' => 'nullable|in:accept,scrap,rework,return', 'values' => 'nullable|array', 'notes' => 'nullable|string|max:1000',
        ]);
        $results = $inspection->plan ? $quality->evaluate($inspection->plan->checks ?? [], $data['values'] ?? []) : null;
        $quality->complete($inspection, ['accepted' => $data['accepted'], 'rejected' => $data['rejected'], 'rework' => $data['rework'] ?? 0, 'disposition' => $data['disposition'] ?? null, 'results' => $results, 'notes' => $data['notes'] ?? null]);

        return redirect()->route('manufacturing.inspections.show', $inspection)->with('success', 'اكتمل الفحص.');
    }
}

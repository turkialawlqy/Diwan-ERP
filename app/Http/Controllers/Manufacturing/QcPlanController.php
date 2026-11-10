<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\QcPlan;
use App\Support\PickerOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QcPlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Manufacturing/Quality/Plans/Index', ['plans' => QcPlan::with('product:id,name_ar')->orderBy('name')->get()]);
    }

    public function create(): Response
    {
        return $this->form(new QcPlan(['applies_to' => 'production', 'is_active' => true, 'checks' => []]));
    }

    public function edit(QcPlan $plan): Response
    {
        return $this->form($plan);
    }

    protected function form(QcPlan $plan): Response
    {
        return Inertia::render('Manufacturing/Quality/Plans/Form', [
            'plan' => $plan,
            'products' => PickerOptions::from(Product::where('is_active', true)->orderBy('name_ar'), ['id', 'name_ar', 'sku'], [$plan->product_id]),
        ]);
    }

    public function store(Request $request)
    {
        QcPlan::create($this->validated($request));

        return redirect()->route('manufacturing.plans.index')->with('success', 'تمت إضافة خطة الجودة.');
    }

    public function update(Request $request, QcPlan $plan)
    {
        $plan->update($this->validated($request));

        return redirect()->route('manufacturing.plans.index')->with('success', 'تم تحديث خطة الجودة.');
    }

    public function destroy(QcPlan $plan)
    {
        $plan->delete();

        return back()->with('success', 'تم حذف خطة الجودة.');
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'nullable|tenant_exists:products,id',
            'applies_to' => 'required|in:production,purchase',
            'is_active' => 'boolean',
            'checks' => 'required|array|min:1|max:30',
            'checks.*.name' => 'required|string|max:255',
            'checks.*.type' => 'required|in:numeric,passfail',
            'checks.*.min' => 'nullable|numeric',
            'checks.*.max' => 'nullable|numeric',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}

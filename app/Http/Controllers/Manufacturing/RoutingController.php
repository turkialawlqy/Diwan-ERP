<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Routing;
use App\Models\WorkCenter;
use App\Support\PickerOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoutingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Manufacturing/Routings/Index', ['routings' => Routing::with('product:id,name_ar')->withCount('operations')->orderBy('code')->get()]);
    }

    public function create(): Response
    {
        return $this->form(new Routing(['is_active' => true]));
    }

    public function edit(Routing $routing): Response
    {
        return $this->form($routing->load('operations'));
    }

    protected function form(Routing $routing): Response
    {
        return Inertia::render('Manufacturing/Routings/Form', [
            'routing' => $routing,
            'products' => PickerOptions::from(Product::where('is_active', true)->where('type', '!=', 'service')->orderBy('name_ar'), ['id', 'name_ar', 'sku'], [$routing->product_id]),
            'workCenters' => WorkCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        DB::transaction(fn () => $this->save(new Routing, $request));

        return redirect()->route('manufacturing.routings.index')->with('success', 'تم حفظ مسار التشغيل.');
    }

    public function update(Request $request, Routing $routing)
    {
        DB::transaction(fn () => $this->save($routing, $request));

        return redirect()->route('manufacturing.routings.index')->with('success', 'تم تحديث مسار التشغيل.');
    }

    public function destroy(Routing $routing)
    {
        if (Bom::where('routing_id', $routing->id)->exists() || ProductionOrder::where('routing_id', $routing->id)->exists()) {
            $routing->update(['is_active' => false]);

            return back()->with('success', 'المسار مرتبط بقوائم أو أوامر فأُوقف ولم يُحذف.');
        }
        $routing->operations()->delete();
        $routing->delete();

        return back()->with('success', 'تم حذف المسار.');
    }

    protected function save(Routing $routing, Request $request): Routing
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('routings', 'code')->where('company_id', $request->user()->company_id)->ignore($routing->id)],
            'name' => 'required|string|max:255',
            'product_id' => 'nullable|tenant_exists:products,id',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:2000',
            'operations' => 'required|array|min:1|max:50',
            'operations.*.seq' => 'required|integer|min:1|max:9999',
            'operations.*.name' => 'required|string|max:255',
            'operations.*.work_center_id' => 'required|tenant_exists:work_centers,id',
            'operations.*.setup_minutes' => 'required|numeric|min:0',
            'operations.*.run_minutes' => 'required|numeric|min:0',
        ]);
        $routing->fill(collect($data)->except('operations')->all() + ['is_active' => $request->boolean('is_active', true)])->save();
        $routing->operations()->delete();
        foreach ($data['operations'] as $op) {
            $routing->operations()->create($op);
        }

        return $routing;
    }
}

<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ServicePlan;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The catalogue of subscription plans (price, billing cycle, trial). */
class ServicePlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Sales/Subscriptions/Plans', [
            'plans' => ServicePlan::withCount(['subscriptions', 'subscriptions as live_count' => fn ($q) => $q->whereIn('status', ['trial', 'active', 'past_due', 'paused'])])->orderBy('code')->get(),
            'cycles' => ServicePlan::CYCLES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Subscriptions/PlanForm', ['plan' => null] + $this->lookups());
    }

    public function edit(ServicePlan $plan): Response
    {
        return Inertia::render('Sales/Subscriptions/PlanForm', ['plan' => $plan] + $this->lookups());
    }

    public function store(Request $request)
    {
        $plan = ServicePlan::create($this->validated($request) + ['code' => $this->nextNumber('service_plan')]);

        if ($json = $this->createdResponse($request, $plan, $plan->name)) {
            return $json;
        }

        return redirect()->route('sales.service-plans.index')->with('success', 'تمت إضافة الخطة');
    }

    public function update(Request $request, ServicePlan $plan)
    {
        // Running subscriptions keep the price and cycle they were sold at; only the plan itself changes.
        $plan->update($this->validated($request));

        return redirect()->route('sales.service-plans.index')->with('success', 'تم حفظ الخطة');
    }

    public function destroy(ServicePlan $plan)
    {
        if ($plan->subscriptions()->exists()) {
            throw ValidationException::withMessages(['plan' => 'عليها اشتراكات فلا تُحذف؛ عطّلها بدل الحذف.']);
        }
        $plan->delete();

        return back()->with('success', 'تم حذف الخطة');
    }

    protected function lookups(): array
    {
        return [
            'cycles' => ServicePlan::CYCLES,
            'products' => Product::where('is_active', true)->orderBy('name_ar')->limit(500)->get(['id', 'name_ar', 'sku', 'type'])->filter(fn ($p) => ! $p->isStockItem())->values(),
            'defaultTax' => TaxProfile::rate(),
        ];
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'cycle' => 'required|in:monthly,quarterly,yearly',
            'interval' => 'nullable|integer|min:1|max:12',
            'trial_days' => 'nullable|integer|min:0|max:365',
            'product_id' => 'nullable|tenant_exists:products,id',
            'is_active' => 'boolean',
        ], [], ['name' => 'اسم الخطة', 'price' => 'السعر', 'cycle' => 'دورة الفوترة']);

        // Billed by a recurring invoice: only service items, never stock.
        if (! empty($data['product_id']) && Product::findOrFail($data['product_id'])->isStockItem()) {
            throw ValidationException::withMessages(['product_id' => 'الاشتراكات للخدمات فقط، اختر صنفًا غير مخزني.']);
        }

        return $data + ['interval' => 1, 'trial_days' => 0, 'tax_rate' => TaxProfile::rate()];
    }
}

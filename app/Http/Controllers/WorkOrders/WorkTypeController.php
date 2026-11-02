<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Work types: the hourly price billed to the customer. */
class WorkTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('WorkOrders/Types/Index', ['types' => WorkType::orderBy('name')->get()]);
    }

    public function create(): Response
    {
        return Inertia::render('WorkOrders/Types/Form', ['type' => null] + $this->lookups());
    }

    public function edit(WorkType $type): Response
    {
        return Inertia::render('WorkOrders/Types/Form', ['type' => $type] + $this->lookups());
    }

    public function store(Request $request)
    {
        $type = WorkType::create($this->validated($request));

        if ($json = $this->createdResponse($request, $type, $type->name)) {
            return $json;
        }

        return redirect()->route('work-orders.types.index')->with('success', 'تمت إضافة نوع العمل');
    }

    public function update(Request $request, WorkType $type)
    {
        $type->update($this->validated($request)); // finished orders keep the amounts they were closed with

        return redirect()->route('work-orders.types.index')->with('success', 'تم حفظ نوع العمل');
    }

    public function destroy(WorkType $type)
    {
        if (WorkOrder::where('work_type_id', $type->id)->exists()) {
            throw ValidationException::withMessages(['type' => 'عليه أوامر عمل فلا يُحذف؛ عطّله بدل الحذف.']);
        }
        $type->delete();

        return back()->with('success', 'تم حذف نوع العمل');
    }

    protected function lookups(): array
    {
        return ['products' => Product::where('is_active', true)->where('type', 'service')->orderBy('name_ar')->limit(500)->get(['id', 'name_ar', 'sku']), 'defaultTax' => TaxProfile::rate()];
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'hourly_rate' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'product_id' => 'nullable|tenant_exists:products,id',
            'min_billable_minutes' => 'nullable|integer|min:0|max:1440',
            'is_active' => 'nullable|boolean',
        ], [], ['name' => 'الاسم', 'hourly_rate' => 'سعر الساعة']);

        return $data + ['tax_rate' => TaxProfile::rate(), 'min_billable_minutes' => 0, 'is_active' => true];
    }
}

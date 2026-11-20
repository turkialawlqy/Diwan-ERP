<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\PickerOptions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Promotional offers applied automatically to invoices, quotations and sales orders. */
class PromotionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => 'nullable|in:active,inactive,all']);
        $status = $filters['status'] ?? 'active';

        return Inertia::render('Sales/Promotions/Index', [
            'promotions' => Promotion::query()
                ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
                ->orderByDesc('priority')->orderByDesc('id')->get()
                ->map(fn (Promotion $p) => $p->toArray() + ['product' => $p->product_id ? Product::whereKey($p->product_id)->value('name_ar') : null, 'category' => $p->category_id ? Category::whereKey($p->category_id)->value('name_ar') : null]),
            'types' => Promotion::TYPES,
            'documents' => Promotion::DOCUMENTS,
            'filters' => ['status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Promotions/Form', ['promotion' => null] + $this->lookups());
    }

    public function edit(Promotion $promotion): Response
    {
        return Inertia::render('Sales/Promotions/Form', ['promotion' => $promotion] + $this->lookups($promotion));
    }

    public function store(Request $request)
    {
        $promotion = Promotion::create($this->validated($request));

        if ($json = $this->createdResponse($request, $promotion, $promotion->name)) {
            return $json;
        }

        return redirect()->route('sales.promotions.index')->with('success', 'تمت إضافة العرض');
    }

    public function update(Request $request, Promotion $promotion)
    {
        $promotion->update($this->validated($request));

        return redirect()->route('sales.promotions.index')->with('success', 'تم تحديث العرض');
    }

    public function destroy(Promotion $promotion)
    {
        $promotion->delete();

        return redirect()->route('sales.promotions.index')->with('success', 'تم حذف العرض (المستندات الصادرة تحتفظ بخصوماتها)');
    }

    protected function lookups(?Promotion $promotion = null): array
    {
        return [
            'types' => Promotion::TYPES,
            'documents' => Promotion::DOCUMENTS,
            'products' => PickerOptions::from(Product::where('is_active', true)->orderBy('name_ar'), ['id', 'name_ar', 'sku'], [$promotion?->product_id]),
            'categories' => Category::where('is_active', true)->orderBy('name_ar')->get(['id', 'name_ar']),
            'groups' => CustomerGroup::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_keys(Promotion::TYPES))],
            'value' => 'nullable|numeric|min:0|required_if:type,percent,fixed',
            'tiers' => 'nullable|array|max:10',
            'tiers.*.min_qty' => 'required|numeric|min:0',
            'tiers.*.value' => 'required|numeric|min:0',
            'buy_qty' => 'nullable|numeric|gt:0|required_if:type,bxgy',
            'get_qty' => 'nullable|numeric|gt:0|required_if:type,bxgy',
            'product_id' => 'nullable|tenant_exists:products,id',
            'category_id' => 'nullable|tenant_exists:categories,id',
            'customer_group_id' => 'nullable|tenant_exists:customer_groups,id',
            'documents' => 'required|array|min:1',
            'documents.*' => Rule::in(array_keys(Promotion::DOCUMENTS)),
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'priority' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'boolean',
        ]);
        if ($data['type'] === 'percent' && collect($data['tiers'] ?? [])->contains(fn ($t) => $t['value'] > 100) || ($data['type'] === 'percent' && ($data['value'] ?? 0) > 100)) {
            throw ValidationException::withMessages(['value' => 'النسبة لا تتجاوز 100%.']);
        }
        $data['value'] = $data['value'] ?? 0;
        $data['tiers'] = $data['type'] === 'bxgy' ? null : (collect($data['tiers'] ?? [])->sortBy('min_qty')->values()->all() ?: null);
        $data['priority'] = $data['priority'] ?? 0;
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}

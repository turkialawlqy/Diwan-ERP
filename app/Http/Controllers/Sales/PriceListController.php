<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PriceListController extends Controller
{
    public function index()
    {
        return Inertia::render('Sales/PriceLists/Index', [
            'lists' => PriceList::withCount(['items', 'customers'])->with('items.product:id,name_ar,sku,selling_price')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        PriceList::create($this->validated($request));

        return back()->with('success', 'تم إنشاء قائمة الأسعار');
    }

    public function update(Request $request, PriceList $priceList)
    {
        $validated = $this->validated($request);

        DB::transaction(function () use ($priceList, $validated) {
            $priceList->update($validated);
            $priceList->items()->delete();
            foreach ($validated['items'] ?? [] as $item) {
                $priceList->items()->create($item);
            }
        });

        return back()->with('success', 'تم حفظ قائمة الأسعار');
    }

    public function destroy(PriceList $priceList)
    {
        if ($priceList->customers()->exists()) {
            return back()->with('error', 'القائمة مرتبطة بعملاء. غيّر قائمة أسعارهم أولاً.');
        }
        $priceList->delete();

        return back()->with('success', 'تم حذف قائمة الأسعار');
    }

    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'items' => 'nullable|array|max:5000',
            'items.*.product_id' => 'required|tenant_exists:products,id',
            'items.*.min_quantity' => 'nullable|numeric|min:0',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        if (! empty($validated['is_default'])) {
            PriceList::where('is_default', true)->update(['is_default' => false]);
        }
        $validated['items'] = array_map(fn ($i) => ['min_quantity' => (float) ($i['min_quantity'] ?? 1) ?: 1] + $i, $validated['items'] ?? []);

        return $validated;
    }
}

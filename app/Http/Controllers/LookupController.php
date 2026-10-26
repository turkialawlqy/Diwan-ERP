<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Sales\PriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bounded type-ahead search used by document forms, so pages never have to
 * ship an entire customer / product / supplier list to the browser.
 */
class LookupController extends Controller
{
    protected const LIMIT = 20;

    public function customers(Request $request): JsonResponse
    {
        $q = $this->term($request);

        $results = Customer::query()
            ->where('status', 'active')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('company_name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "{$q}%")
                ->orWhere('tax_number', 'like', "{$q}%")
                ->orWhere('code', $q)))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'name', 'company_name', 'tax_number', 'phone', 'current_balance']);

        return response()->json(['data' => $results]);
    }

    public function products(Request $request): JsonResponse
    {
        $q = $this->term($request);

        $results = Product::query()
            ->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name_ar', 'like', "%{$q}%")
                ->orWhere('name_en', 'like', "%{$q}%")
                ->orWhere('sku', 'like', "{$q}%")
                ->orWhere('barcode', $q)))
            ->orderBy('name_ar')
            ->limit(self::LIMIT)
            ->get(['id', 'name_ar', 'name_en', 'sku', 'barcode', 'selling_price', 'purchase_price', 'tax_rate', 'unit_id', 'category_id', 'description']);

        return response()->json(['data' => $results]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $q = $this->term($request);

        $results = Supplier::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('company_name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "{$q}%")
                ->orWhere('tax_number', 'like', "{$q}%")))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['data' => $results]);
    }

    /**
     * Customer specific selling price (price lists / quantity breaks).
     */
    public function price(Request $request, PriceResolver $resolver): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|tenant_exists:products,id',
            'customer_id' => 'nullable|tenant_exists:customers,id',
            'quantity' => 'nullable|numeric|min:0',
        ]);

        return response()->json($resolver->price(
            Product::findOrFail($validated['product_id']),
            isset($validated['customer_id']) ? Customer::find($validated['customer_id']) : null,
            (float) ($validated['quantity'] ?? 1),
        ));
    }

    protected function term(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 100);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
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

    protected function term(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 100);
    }
}

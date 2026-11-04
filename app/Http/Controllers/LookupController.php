<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Department;
use App\Models\JobPosition;
use App\Models\Product;
use App\Models\Property;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WorkCenter;
use App\Models\WorkShift;
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
     * Small master-data lists for the selects inside "create ..." dialogs: id and
     * a ready label only, scoped to the company by the models themselves.
     */
    public function list(Request $request, string $name): JsonResponse
    {
        $lists = [
            'categories' => [Category::class, 'name_ar', fn ($m) => $m->name_ar],
            'units' => [Unit::class, 'name_ar', fn ($m) => $m->name_ar],
            'warehouses' => [Warehouse::class, 'name_ar', fn ($m) => $m->name_ar],
            'branches' => [Branch::class, 'name_ar', fn ($m) => $m->name_ar],
            'cost_centers' => [CostCenter::class, 'code', fn ($m) => $m->code.' - '.$m->name_ar],
            'departments' => [Department::class, 'name', fn ($m) => $m->name],
            'positions' => [JobPosition::class, 'title', fn ($m) => $m->title],
            'shifts' => [WorkShift::class, 'name', fn ($m) => $m->name],
            'asset_categories' => [AssetCategory::class, 'name_ar', fn ($m) => $m->name_ar],
            'work_centers' => [WorkCenter::class, 'name', fn ($m) => $m->name],
            'customer_groups' => [CustomerGroup::class, 'code', fn ($m) => $m->name],
            'accounts' => [Account::class, 'code', fn ($m) => $m->code.' - '.$m->name_ar],
            'customers' => [Customer::class, 'name', fn ($m) => $m->name],
            'properties' => [Property::class, 'name', fn ($m) => $m->name],
        ];
        abort_unless(isset($lists[$name]), 404);
        // Customer names only for those who may pick a customer somewhere (the same rule as the customer search).
        if ($name === 'customers') {
            $user = auth()->user();
            abort_unless($user && ($user->isCompanyOwner() || collect((array) (config('erp_permissions.routes')['lookup.customers'] ?? []))->contains(fn ($p) => $user->can($p))), 403);
        }
        [$model, $order, $label] = $lists[$name];

        $query = $model::query();
        // A user only sees (and so can only pick) the branches he works in.
        if ($model === Branch::class && ($allowed = auth()->user()?->allowedBranchIds()) !== null) {
            $query->whereIn('id', $allowed);
        }
        if ($name === 'accounts') {
            $query->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
                ->when($request->boolean('parents'), fn ($q) => $q->where('is_parent', true));
        }

        return response()->json(['data' => $query->orderBy($order)->limit(500)->get()->map(fn ($m) => ['id' => $m->getKey(), 'label' => $label($m)])->values()]);
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

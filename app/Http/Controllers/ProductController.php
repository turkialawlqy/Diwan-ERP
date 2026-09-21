<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProductController extends Controller
{
    /**
     * Display a listing of products.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $categoryId = $request->input('category_id');

        $query = Product::with(['category', 'unit', 'stocks']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%")
                  ->orWhere('name_en', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20))
            ->withQueryString();

        $categories = Category::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        $stats = [
            'total_products' => Product::count(),
            'total_categories' => Category::count(),
            'low_stock_count' => Product::where('track_inventory', true)
                ->whereHas('stocks', function ($sq) {
                    $sq->havingRaw('SUM(quantity) <= 5');
                })->count(),
        ];

        return Inertia::render('Inventory/Products/Index', [
            'products' => $products,
            'categories' => $categories,
            'filters' => [
                'search' => $search,
                'category_id' => $categoryId,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Show the form for creating a new product.
     */
    public function create()
    {
        $categories = Category::where('is_active', true)->get(['id', 'name_ar', 'name_en']);
        $units = Unit::all(['id', 'name_ar', 'name_en']);
        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        return Inertia::render('Inventory/Products/Create', [
            'categories' => $categories,
            'units' => $units,
            'warehouses' => $warehouses,
            'suggestedSku' => 'PRD-' . date('y') . '-' . sprintf('%05d', Product::count() + 1),
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'min_stock_alert' => 'nullable|numeric|min:0',
            'track_inventory' => 'boolean',
            'type' => 'required|in:product,service',
            'initial_stock' => 'nullable|numeric|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $companyId = app(\App\Services\TenantManager::class)->getTenantId() 
            ?? auth()->user()?->company_id 
            ?? \App\Models\Company::first()?->id ?? 1;

        DB::transaction(function () use ($validated, $companyId) {
            $product = Product::create([
                'company_id' => $companyId,
                'category_id' => $validated['category_id'] ?? null,
                'unit_id' => $validated['unit_id'] ?? null,
                'name_ar' => $validated['name_ar'],
                'name_en' => $validated['name_en'] ?? null,
                'sku' => $validated['sku'] ?? ('PRD-' . uniqid()),
                'barcode' => $validated['barcode'] ?? null,
                'description' => $validated['description'] ?? null,
                'purchase_price' => $validated['purchase_price'],
                'selling_price' => $validated['selling_price'],
                'tax_rate' => $validated['tax_rate'] ?? 15.00,
                'min_stock_alert' => $validated['min_stock_alert'] ?? 5,
                'track_inventory' => $validated['track_inventory'] ?? true,
                'type' => $validated['type'],
                'is_active' => true,
            ]);

            $initialStock = (float) ($validated['initial_stock'] ?? 0);
            if ($initialStock > 0 && $validated['type'] === 'product') {
                $warehouseId = $validated['warehouse_id'] ?? Warehouse::first()?->id;
                if ($warehouseId) {
                    InventoryStock::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $initialStock,
                    ]);
                }
            }
        });

        return redirect()->route('inventory.products.index')->with('success', 'تمت إضافة المنتج بنجاح');
    }

    /**
     * Show the form for editing the product.
     */
    public function edit(Product $product)
    {
        $categories = Category::where('is_active', true)->get(['id', 'name_ar', 'name_en']);
        $units = Unit::all(['id', 'name_ar', 'name_en']);

        return Inertia::render('Inventory/Products/Edit', [
            'product' => $product,
            'categories' => $categories,
            'units' => $units,
        ]);
    }

    /**
     * Update the specified product.
     */
    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'min_stock_alert' => 'nullable|numeric|min:0',
            'track_inventory' => 'boolean',
            'type' => 'required|in:product,service',
        ]);

        $product->update($validated);

        return redirect()->route('inventory.products.index')->with('success', 'تم تحديث بيانات المنتج بنجاح');
    }

    /**
     * Remove the specified product.
     */
    public function destroy(Product $product)
    {
        $product->delete();
        return redirect()->route('inventory.products.index')->with('success', 'تم حذف المنتج بنجاح');
    }
}

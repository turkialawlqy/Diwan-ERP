<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Accounting\DocumentPoster;
use App\Services\Inventory\DocumentStock;
use App\Services\Inventory\StockService;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
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
            ->paginate($this->perPage($request))
            ->withQueryString();
        $products->getCollection()->each->append('total_stock');

        $categories = Category::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        $stats = [
            'total_products' => Product::count(),
            'total_categories' => Category::count(),
            // Products at or below their own alert level (5 when unset),
            // counting those with no stock rows at all as zero stock.
            'low_stock_count' => Product::where('track_inventory', true)
                ->whereRaw('COALESCE((SELECT SUM(s.quantity) FROM inventory_stocks s WHERE s.product_id = products.id), 0) <= COALESCE(products.min_stock_alert, 5)')
                ->count(),
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
            'suggestedSku' => $this->peekNumber('product'),
            'vatCategories' => config('zatca.vat_categories'),
            'exemptionReasons' => config('zatca.exemption_reasons'),
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
            'category_id' => 'nullable|tenant_exists:categories,id',
            'unit_id' => 'nullable|tenant_exists:units,id',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'purchase_price' => 'nullable|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'min_stock_alert' => 'nullable|numeric|min:0',
            'track_inventory' => 'nullable|boolean',
            'type' => 'nullable|in:product,service',
            'initial_stock' => 'nullable|numeric|min:0',
            'tracking' => 'nullable|in:none,batch,serial',
            'reorder_level' => 'nullable|numeric|min:0',
            'reorder_quantity' => 'nullable|numeric|min:0',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'serials' => 'nullable|array',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'vat_category' => 'nullable|in:S,Z,E,O',
            'vat_exemption_code' => ['nullable', 'string', function ($attribute, $value, $fail) use ($request) {
                if (! isset(config('zatca.exemption_reasons')[$request->input('vat_category', 'S')][$value])) {
                    $fail('سبب الإعفاء لا يناسب الفئة الضريبية المختارة.');
                }
            }],
        ]);

        $companyId = app(TenantManager::class)->getTenantId()
            ?? auth()->user()?->company_id;

        if (! $companyId) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        $product = DB::transaction(function () use ($validated, $companyId) {
            $productType = $validated['type'] ?? 'product';
            $purchasePrice = $validated['purchase_price'] ?? 0;
            $trackInventory = isset($validated['track_inventory']) ? (bool) $validated['track_inventory'] : ($productType === 'product');

            $product = Product::create([
                'company_id' => $companyId,
                'category_id' => $validated['category_id'] ?? null,
                'unit_id' => $validated['unit_id'] ?? null,
                'name_ar' => $validated['name_ar'],
                'name_en' => $validated['name_en'] ?? null,
                'sku' => $validated['sku'] ?? $this->nextNumber('product'),
                'barcode' => $validated['barcode'] ?? null,
                'description' => $validated['description'] ?? null,
                'purchase_price' => $purchasePrice,
                'selling_price' => $validated['selling_price'],
                'tax_rate' => ($validated['vat_category'] ?? 'S') === 'S' ? ($validated['tax_rate'] ?? TaxProfile::rate()) : 0,
                'min_stock_alert' => $validated['min_stock_alert'] ?? 5,
                'track_inventory' => $trackInventory,
                'tracking' => $validated['tracking'] ?? 'none',
                'reorder_level' => $validated['reorder_level'] ?? null,
                'reorder_quantity' => $validated['reorder_quantity'] ?? null,
                'average_cost' => $purchasePrice,
                'type' => $productType,
                'is_active' => true,
                'vat_category' => $validated['vat_category'] ?? 'S',
                'vat_exemption_code' => ($validated['vat_category'] ?? 'S') === 'S' ? null : ($validated['vat_exemption_code'] ?? null),
            ]);

            // Opening stock goes through the stock ledger and is posted against
            // opening balance equity.
            $initialStock = (float) ($validated['initial_stock'] ?? 0);
            if ($initialStock > 0 && $product->isStockItem()) {
                $movement = app(StockService::class)->receive([
                    'product_id' => $product->id,
                    'warehouse_id' => app(DocumentStock::class)->warehouse($validated['warehouse_id'] ?? null),
                    'quantity' => $initialStock,
                    'unit_cost' => $purchasePrice,
                    'type' => 'opening',
                    'source' => $product,
                    'reference' => $product->sku,
                    'batch_number' => $validated['batch_number'] ?? null,
                    'expiry_date' => $validated['expiry_date'] ?? null,
                    'serials' => $validated['serials'] ?? [],
                ]);

                app(DocumentPoster::class)->openingBalance(
                    $product,
                    ['role' => 'inventory', 'side' => 'debit'],
                    (float) $movement->total_cost,
                    now(),
                    'رصيد افتتاحي للصنف '.$product->name_ar,
                );
            }

            return $product;
        });

        if ($this->wantsJsonResponse($request)) {
            $product->load(['category', 'unit']);

            return response()->json([
                'success' => true,
                'product' => $product,
                'message' => 'تمت إضافة المنتج بنجاح',
            ], 201);
        }

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
            'vatCategories' => config('zatca.vat_categories'),
            'exemptionReasons' => config('zatca.exemption_reasons'),
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
            'category_id' => 'nullable|tenant_exists:categories,id',
            'unit_id' => 'nullable|tenant_exists:units,id',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'min_stock_alert' => 'nullable|numeric|min:0',
            'track_inventory' => 'boolean',
            'type' => 'required|in:product,service',
            'tracking' => 'nullable|in:none,batch,serial',
            'reorder_level' => 'nullable|numeric|min:0',
            'reorder_quantity' => 'nullable|numeric|min:0',
            'vat_category' => 'nullable|in:S,Z,E,O',
            'vat_exemption_code' => ['nullable', 'string', function ($attribute, $value, $fail) use ($request) {
                if (! isset(config('zatca.exemption_reasons')[$request->input('vat_category', 'S')][$value])) {
                    $fail('سبب الإعفاء لا يناسب الفئة الضريبية المختارة.');
                }
            }],
        ]);
        $validated = $this->normaliseVat($validated);

        // Tracking mode cannot change while stock exists (batches/serials would be inconsistent).
        if (isset($validated['tracking']) && $validated['tracking'] !== $product->tracking
            && InventoryStock::where('product_id', $product->id)->where('quantity', '!=', 0)->exists()) {
            return back()->withErrors(['tracking' => 'لا يمكن تغيير نوع التتبع لصنف عليه رصيد مخزون.']);
        }

        $product->update($validated);

        return redirect()->route('inventory.products.index')->with('success', 'تم تحديث بيانات المنتج بنجاح');
    }

    /**
     * Remove the specified product.
     */
    public function destroy(Product $product)
    {
        if (InventoryStock::where('product_id', $product->id)->where('quantity', '!=', 0)->exists()) {
            return back()->with('error', 'لا يمكن حذف صنف عليه رصيد مخزون.');
        }

        // Archived (soft deleted): historical documents keep their lines.
        $product->delete();

        return redirect()->route('inventory.products.index')->with('success', 'تم حذف المنتج بنجاح');
    }

    /**
     * Product card: stock per warehouse, units, kit components, batches, serials.
     */
    public function show(Product $product)
    {
        $product->load(['category:id,name_ar', 'unit:id,name_ar', 'units.unit:id,name_ar', 'kitItems.component:id,name_ar,sku']);

        return Inertia::render('Inventory/Products/Show', [
            'product' => $product,
            'stocks' => InventoryStock::with('warehouse:id,name_ar')->where('product_id', $product->id)->get(),
            'batches' => ProductBatch::with('warehouse:id,name_ar')->where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('expiry_date')->limit(200)->get(),
            'serials' => ProductSerial::where('product_id', $product->id)->where('status', 'in_stock')->limit(500)->get(['id', 'serial_number', 'warehouse_id']),
            'units' => Unit::get(['id', 'name_ar']),
        ]);
    }

    public function storeUnit(Request $request, Product $product)
    {
        $validated = $request->validate([
            'unit_id' => 'required|tenant_exists:units,id',
            'factor' => 'required|numeric|gt:0',
            'barcode' => 'nullable|string|max:100',
            'selling_price' => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
        ]);

        if ((int) $validated['unit_id'] === (int) $product->unit_id) {
            return back()->withErrors(['unit_id' => 'هذه هي الوحدة الأساسية للصنف.']);
        }

        ProductUnit::updateOrCreate(['product_id' => $product->id, 'unit_id' => $validated['unit_id']], $validated);

        return back()->with('success', 'تم حفظ وحدة القياس');
    }

    public function destroyUnit(Product $product, ProductUnit $unit)
    {
        abort_unless((int) $unit->product_id === (int) $product->id, 404);
        $unit->delete();

        return back()->with('success', 'تم حذف الوحدة');
    }

    /**
     * Define the components of a kit (bundle). Kits hold no stock themselves.
     */
    public function syncKit(Request $request, Product $product)
    {
        $validated = $request->validate([
            'is_kit' => 'required|boolean',
            'items' => 'nullable|array|max:100',
            'items.*.component_id' => 'required|tenant_exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);

        if ($validated['is_kit'] && InventoryStock::where('product_id', $product->id)->where('quantity', '!=', 0)->exists()) {
            return back()->withErrors(['is_kit' => 'لا يمكن تحويل صنف عليه رصيد إلى طقم.']);
        }

        DB::transaction(function () use ($validated, $product) {
            $product->update(['is_kit' => $validated['is_kit']]);
            $product->kitItems()->delete();

            foreach ($validated['is_kit'] ? ($validated['items'] ?? []) : [] as $item) {
                if ((int) $item['component_id'] === (int) $product->id) {
                    continue;
                }
                $product->kitItems()->create($item);
            }
        });

        return back()->with('success', 'تم حفظ مكونات الطقم');
    }

    /** Non-standard categories carry no VAT; the standard one carries no reason. */
    protected function normaliseVat(array $validated): array
    {
        $category = $validated['vat_category'] ?? 'S';
        $validated['vat_category'] = $category;
        if ($category === 'S') {
            $validated['vat_exemption_code'] = null;
        } else {
            $validated['tax_rate'] = 0;
            $validated['vat_exemption_code'] = ($validated['vat_exemption_code'] ?? null) ?: null;
        }

        return $validated;
    }
}

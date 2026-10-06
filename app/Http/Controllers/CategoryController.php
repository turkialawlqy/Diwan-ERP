<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories.
     */
    public function index()
    {
        $categories = Category::withCount('products')
            ->orderBy('name_ar')
            ->get();

        return Inertia::render('Inventory/Categories/Index', [
            'categories' => $categories,
        ]);
    }

    /**
     * Store a new category.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
        ]);

        $companyId = app(TenantManager::class)->getTenantId()
            ?? auth()->user()?->company_id;

        if (! $companyId) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        $category = Category::create(array_merge($validated, [
            'company_id' => $companyId,
            'is_active' => true,
        ]));

        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'success' => true,
                'category' => $category,
                'message' => 'تمت إضافة التصنيف بنجاح',
            ], 201);
        }

        return back()->with('success', 'تمت إضافة التصنيف بنجاح');
    }

    /**
     * Delete category.
     */
    public function destroy(Category $category)
    {
        if ($category->products()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف التصنيف لوجود منتجات مرتبطة به');
        }

        $category->delete();

        return back()->with('success', 'تم حذف التصنيف بنجاح');
    }
}
